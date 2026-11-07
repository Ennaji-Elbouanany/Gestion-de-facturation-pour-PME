"""Manual release controller. Real deployments only occur through main().
No migrations or service/environment updates are performed by this tool.
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


class ReleaseError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise ReleaseError(message)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward API credentials to a redirect target.


class Api:
    def __init__(self, base, token=None):
        self.base = base
        self.token = token

    def __call__(self, path, body=None, text=False):
        headers = {"Accept": "application/json", "User-Agent": "billing-controlled-release"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        if body is not None:
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.base + path, headers=headers,
                                     data=None if body is None else json.dumps(body).encode())
        try:
            with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as response:
                return response.read().decode() if text else json.load(response)
        except Exception:
            # Do not print URLs, response bodies, headers or exception messages.
            raise ReleaseError("Remote verification failed; inspect the provider dashboard securely.") from None


def origin(value):
    parsed = urllib.parse.urlsplit(value)
    require(parsed.scheme == "https" and parsed.hostname and not parsed.username and not parsed.password
            and not parsed.query and not parsed.fragment and parsed.path in ("", "/"),
            "Public service URLs must be HTTPS origins without credentials, paths or query strings.")
    return value.rstrip("/")


REQUIRED_JOBS = {"Backend Laravel", "Backend finance, concurrency and RBAC (MySQL 8)",
                 "Frontend React and Vite", "Deployment safeguards and Docker"}
ACTIVE = {"created", "build_in_progress", "pre_deploy_in_progress", "update_in_progress", "queued"}
FAILED = {"build_failed", "pre_deploy_failed", "update_failed", "canceled", "deactivated"}


def validate_ci(gh, repo, sha, review=True):
    require(re.fullmatch(r"[0-9a-f]{40}", sha), "A full lowercase commit SHA is required.")
    require(re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo), "Invalid repository.")
    base = "/repos/" + repo
    require(gh(base)["default_branch"] == "main", "Only the configured main branch is authorized.")
    compare = gh(base + "/compare/" + sha + "...main")
    require(compare.get("merge_base_commit", {}).get("sha") == sha, "Commit does not belong to main.")
    runs = gh(base + "/actions/workflows/ci.yml/runs?head_sha=" + sha + "&event=push&per_page=100")["workflow_runs"]
    runs = [r for r in runs if r.get("head_sha") == sha and r.get("head_branch") == "main"
            and r.get("event") == "push"]
    require(runs and runs[0].get("status") == "completed" and runs[0].get("conclusion") == "success",
            "The latest push CI for this exact commit must have completed successfully.")
    jobs = gh(base + "/actions/runs/" + str(runs[0]["id"]) + "/jobs?per_page=100")["jobs"]
    passed = {j["name"] for j in jobs if j.get("conclusion") == "success"}
    require(REQUIRED_JOBS <= passed, "Required CI jobs must all pass; skipped jobs do not qualify.")
    environment = gh(base + "/environments/production")
    require(environment.get("deployment_branch_policy"), "Configure production deployment branch restrictions first.")
    if review:
        require(any(r.get("type") == "required_reviewers" and r.get("reviewers")
                    for r in environment.get("protection_rules", [])), "Configure production reviewers first.")


def check_service(render, service_id, kind, repo, public_url):
    require(re.fullmatch(r"srv-[a-z0-9]+", service_id), "Invalid Render service identifier.")
    service = render("/services/" + service_id)
    require(service.get("type") == kind and service.get("branch") == "main", "Wrong service type or branch.")
    require(service.get("repo", "").rstrip("/").removesuffix(".git") == "https://github.com/" + repo,
            "Render service is linked to a different repository.")
    require(service.get("autoDeploy") == "no", "Disable native Render auto-deploy on both services first.")
    require(service.get("suspended") == "not_suspended", "Service is suspended.")
    details = service.get("serviceDetails", {})
    if kind == "web_service":
        require(details.get("env") == "docker", "Backend must use the audited Docker runtime.")
        runtime = details.get("envSpecificDetails", {})
        require(not runtime.get("dockerCommand"), "Remove the backend Docker command override first.")
        require(not runtime.get("preDeployCommand"), "Remove automatic pre-deploy commands first; migrations are separate.")
        require(details.get("healthCheckPath") == "/ready", "Configure Render HTTP readiness path /ready first.")
    entries = render("/services/" + service_id + "/env-vars?limit=100")
    require(len(entries) < 100, "Environment inventory requires pagination; refusing incomplete verification.")
    values = {e["envVar"]["key"]: e["envVar"].get("value") for e in entries}
    deployments = render("/services/" + service_id + "/deploys?limit=20")
    require(not any(e["deploy"]["status"] in ACTIVE for e in deployments), "A Render deployment is already pending.")
    # Values stay in memory only. No environment payload is logged or persisted.
    if kind == "web_service":
        require(values.get("APP_ENV") == "production" and str(values.get("APP_DEBUG", "")).lower() == "false",
                "Production environment and disabled debug must be explicitly configured.")
        require(values.get("APP_KEY") and values.get("DB_CONNECTION") == "mysql", "Missing backend runtime prerequisites.")
        require(values.get("DB_URL") or all(values.get(k) for k in ("DB_HOST", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD")),
                "Incomplete database configuration.")
        require(origin(values.get("APP_URL", "")) == public_url, "Backend public URL mismatch.")
    return values


def preflight(gh, render, env):
    required = ["APPROVED_SHA", "GITHUB_REPOSITORY", "GH_TOKEN", "RENDER_API_KEY",
                "RENDER_BACKEND_SERVICE_ID", "RENDER_FRONTEND_SERVICE_ID", "BACKEND_URL", "FRONTEND_URL"]
    require(all(env.get(k) for k in required), "Missing release prerequisites; no deployment was requested.")
    require(env.get("DEPLOY_CONFIRMATION") == "DEPLOY", "Explicit DEPLOY confirmation is required.")
    require(env.get("GITHUB_REF") == "refs/heads/main", "Run the trusted workflow on main.")
    sha, repo = env["APPROVED_SHA"], env["GITHUB_REPOSITORY"]
    backend, frontend = origin(env["BACKEND_URL"]), origin(env["FRONTEND_URL"])
    require(env["RENDER_BACKEND_SERVICE_ID"] != env["RENDER_FRONTEND_SERVICE_ID"], "Services must be distinct.")
    validate_ci(gh, repo, sha, env.get("REQUIRE_PRODUCTION_REVIEW", "true") != "false")
    back = check_service(render, env["RENDER_BACKEND_SERVICE_ID"], "web_service", repo, backend)
    front = check_service(render, env["RENDER_FRONTEND_SERVICE_ID"], "static_site", repo, frontend)
    require(origin(front.get("VITE_API_URL", "")) == backend, "Frontend API build URL mismatch.")
    require(origin(back.get("FRONTEND_URL", "")) == frontend, "Backend CORS origin mismatch.")
    return sha, backend, frontend


def wait_live(render, service, deploy_id, sha, sleep=time.sleep, clock=time.monotonic):
    deadline = clock() + 2400
    while clock() < deadline:
        deployment = render("/services/" + service + "/deploys/" + deploy_id)
        require(deployment.get("commit", {}).get("id") == sha, "Render deployment commit mismatch.")
        status = deployment.get("status")
        if status == "live":
            return
        require(status not in FAILED, "Render deployment failed; frontend will not be advanced automatically.")
        sleep(10)
    raise ReleaseError("Deployment monitoring timed out. It may still be running; do not retry blindly.")


def verify_http(public, sha, backend, get=None, sleep=time.sleep, clock=time.monotonic):
    get = get or Api(public)
    deadline = clock() + 180
    while clock() < deadline:
        try:
            result = get(("/ready" if backend else "/release.json") + "?release=" + sha)
            require(result.get("release_sha") == sha, "Public release version mismatch.")
            if backend:
                require(result.get("ready") is True, "Backend is not ready.")
            else:
                html = get("/", text=True)
                require('<script' in html and 'id="root"' in html, "Frontend HTML is unavailable.")
            return result
        except ReleaseError:
            sleep(5)
    raise ReleaseError("Public health/version verification failed; no automatic rollback was performed.")


def deliver(render, env, sha, backend, frontend, verify=verify_http):
    for key, public, is_backend in [("RENDER_BACKEND_SERVICE_ID", backend, True),
                                    ("RENDER_FRONTEND_SERVICE_ID", frontend, False)]:
        service = env[key]
        # A write is never retried: an ambiguous network outcome needs operator review.
        deployment = render("/services/" + service + "/deploys", {"commitId": sha, "clearCache": "do_not_clear"})
        require(deployment.get("id"), "No deployment identifier returned; inspect Render before retrying.")
        wait_live(render, service, deployment["id"], sha)
        result = verify(public, sha, is_backend)
        if not is_backend:
            require(result.get("api_origin") == backend, "Published frontend points to a different API.")
        print("Backend verified." if is_backend else "Frontend verified.")


def main():
    env = os.environ
    gh = Api("https://api.github.com", env.get("GH_TOKEN"))
    render = Api("https://api.render.com/v1", env.get("RENDER_API_KEY"))
    sha, backend, frontend = preflight(gh, render, env)
    print("All prerequisites verified. No migrations will be executed.")
    deliver(render, env, sha, backend, frontend)


if __name__ == "__main__":
    try:
        main()
    except ReleaseError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("Unexpected release failure. Inspect provider state before any retry.", file=sys.stderr)
        sys.exit(1)

import importlib.util
import pathlib
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('release', ROOT / 'scripts/ops/deploy.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
SHA = 'a' * 40


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.env = dict(APPROVED_SHA=SHA, GITHUB_REPOSITORY='owner/repo', GH_TOKEN='not-a-real-token',
                        RENDER_API_KEY='not-a-real-key', RENDER_BACKEND_SERVICE_ID='srv-backend',
                        RENDER_FRONTEND_SERVICE_ID='srv-frontend', BACKEND_URL='https://api.example.test',
                        FRONTEND_URL='https://app.example.test', DEPLOY_CONFIRMATION='DEPLOY',
                        GITHUB_REF='refs/heads/main')
        self.calls = []

    def gh(self, path):
        if path.endswith('/owner/repo'):
            return {'default_branch': 'main'}
        if '/compare/' in path:
            return {'merge_base_commit': {'sha': SHA}}
        if '/environments/' in path:
            return {'deployment_branch_policy': {'custom_branch_policies': True},
                    'protection_rules': [{'type': 'required_reviewers', 'reviewers': [{'type': 'User'}]}]}
        if '/jobs?' in path:
            return {'jobs': [{'name': name, 'conclusion': 'success'} for name in release.REQUIRED_JOBS]}
        return {'workflow_runs': [{'id': 1, 'head_sha': SHA, 'head_branch': 'main',
                                   'event': 'push', 'status': 'completed', 'conclusion': 'success'}]}

    def render(self, path, body=None):
        self.calls.append((path, body))
        backend = 'srv-backend' in path
        if body is not None:
            return {'id': 'dep-fixture'}
        if '/deploys/' in path:
            return {'status': 'live', 'commit': {'id': SHA}}
        if '/deploys?' in path:
            return []
        if '/env-vars?' in path:
            values = dict(APP_ENV='production', APP_DEBUG='false', APP_KEY='fixture', DB_CONNECTION='mysql',
                          DB_URL='fixture', APP_URL=self.env['BACKEND_URL'], FRONTEND_URL=self.env['FRONTEND_URL']) if backend else {'VITE_API_URL': self.env['BACKEND_URL']}
            return [{'envVar': {'key': key, 'value': value}} for key, value in values.items()]
        return {'type': 'web_service' if backend else 'static_site', 'branch': 'main',
                'repo': 'https://github.com/owner/repo', 'autoDeploy': 'no', 'suspended': 'not_suspended',
                'serviceDetails': {'env': 'docker', 'healthCheckPath': '/ready', 'envSpecificDetails': {}}}

    def test_complete_preflight_is_read_only(self):
        self.assertEqual(release.preflight(self.gh, self.render, self.env)[0], SHA)
        self.assertTrue(all(body is None for _, body in self.calls))

    def test_missing_prerequisite_never_calls_render(self):
        for key in ['RENDER_API_KEY', 'RENDER_FRONTEND_SERVICE_ID', 'FRONTEND_URL']:
            with self.subTest(key=key):
                with self.assertRaises(release.ReleaseError):
                    release.preflight(self.gh, self.render, {**self.env, key: ''})
                self.assertEqual(self.calls, [])

    def test_wrong_sha_branch_ci_or_skipped_job_never_deploy(self):
        for fault in ['sha', 'branch', 'ci', 'job', 'review']:
            def bad_gh(path):
                result = self.gh(path)
                if fault == 'sha' and '/compare/' in path:
                    result['merge_base_commit']['sha'] = 'b' * 40
                if fault == 'branch' and '/runs?' in path:
                    result['workflow_runs'][0]['head_branch'] = 'other'
                if fault == 'ci' and '/runs?' in path:
                    result['workflow_runs'][0]['conclusion'] = 'failure'
                if fault == 'job' and '/jobs?' in path:
                    result['jobs'][0]['conclusion'] = 'skipped'
                if fault == 'review' and '/environments/' in path:
                    result['protection_rules'] = []
                return result
            with self.subTest(fault=fault), self.assertRaises(release.ReleaseError):
                release.preflight(bad_gh, self.render, self.env)
            self.assertFalse(any(body is not None for _, body in self.calls))

    def test_native_autodeploy_or_wrong_frontend_config_blocks_before_writes(self):
        for fault in ['auto', 'api', 'command']:
            def bad_render(path, body=None):
                result = self.render(path, body)
                if fault == 'auto' and isinstance(result, dict) and 'autoDeploy' in result:
                    result['autoDeploy'] = 'yes'
                if fault == 'api' and 'srv-frontend/env-vars' in path:
                    result[0]['envVar']['value'] = 'https://wrong.example.test'
                if fault == 'command' and isinstance(result, dict) and 'serviceDetails' in result:
                    result['serviceDetails']['envSpecificDetails']['dockerCommand'] = 'unsafe override'
                return result
            with self.subTest(fault=fault), self.assertRaises(release.ReleaseError):
                release.preflight(self.gh, bad_render, self.env)
            self.assertFalse(any(body is not None for _, body in self.calls))

    def test_backend_live_and_http_verified_before_frontend_trigger(self):
        events = []
        def render(path, body=None):
            if body:
                events.append('backend-start' if 'srv-backend' in path else 'frontend-start')
                self.assertEqual(body['commitId'], SHA)
            return self.render(path, body)
        def verify(url, sha, backend):
            events.append('backend-verified' if backend else 'frontend-verified')
            return {'api_origin': self.env['BACKEND_URL']}
        release.deliver(render, self.env, SHA, self.env['BACKEND_URL'], self.env['FRONTEND_URL'], verify)
        self.assertEqual(events, ['backend-start', 'backend-verified', 'frontend-start', 'frontend-verified'])

    def test_backend_failure_or_wrong_commit_prevents_frontend(self):
        for fault in ['build_failed', 'wrong_commit', 'http']:
            self.calls = []
            def render(path, body=None):
                result = self.render(path, body)
                if '/deploys/' in path:
                    if fault == 'build_failed': result['status'] = 'build_failed'
                    if fault == 'wrong_commit': result['commit']['id'] = 'b' * 40
                return result
            def verify(*args):
                raise release.ReleaseError('HTTP failure')
            with self.subTest(fault=fault), self.assertRaises(release.ReleaseError):
                release.deliver(render, self.env, SHA, self.env['BACKEND_URL'], self.env['FRONTEND_URL'], verify)
            self.assertFalse(any('srv-frontend' in path for path, _ in self.calls))

    def test_timeout_does_not_trigger_another_deployment(self):
        clock = iter([0, 2401])
        with self.assertRaises(release.ReleaseError):
            release.wait_live(self.render, 'srv-backend', 'dep-test', SHA, clock=lambda: next(clock))
        self.assertEqual(self.calls, [])

    def test_credentials_are_not_exposed_on_remote_error(self):
        with patch.object(release.urllib.request, 'build_opener', side_effect=RuntimeError('secret-value')):
            with self.assertRaises(release.ReleaseError) as caught:
                release.Api('https://api.render.com', 'secret-token')('/test')
        self.assertNotIn('secret', str(caught.exception))

    def test_only_https_origins_without_credentials_are_allowed(self):
        for value in ['http://example.test', 'https://user:pass@example.test', 'https://example.test/?key=secret']:
            with self.assertRaises(release.ReleaseError): release.origin(value)

    def test_frontend_probe_requires_matching_metadata_and_real_html(self):
        def good(path, text=False):
            return '<div id="root"></div><script src="/assets/app.js"></script>' if text else {'release_sha': SHA}
        self.assertEqual(release.verify_http('https://app.example.test', SHA, False, get=good)['release_sha'], SHA)
        clock = iter([0, 0, 181])
        with self.assertRaises(release.ReleaseError):
            release.verify_http('https://app.example.test', SHA, False,
                                get=lambda path, text=False: '' if text else {'release_sha': SHA},
                                sleep=lambda _: None, clock=lambda: next(clock))

    def test_workflow_is_manual_and_never_cancels_running_delivery(self):
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        triggers = workflow.split('permissions:', 1)[0]
        self.assertIn('workflow_dispatch:', triggers)
        self.assertNotRegex(triggers, r'workflow_run:|push:|pull_request:|schedule:')
        self.assertIn('cancel-in-progress: false', workflow)


if __name__ == '__main__':
    unittest.main()

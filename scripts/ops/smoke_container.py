"""HTTP smoke test against a newly created local container, never a supplied URL."""
import json
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid


def docker(*args):
    result = subprocess.run(["docker", *args], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError("Local Docker operation failed (details intentionally not logged).")
    return result.stdout.strip()


def main(image, sha):
    name = "billing-smoke-" + uuid.uuid4().hex
    container = None
    try:
        container = docker("run", "-d", "--name", name, "--memory=512m", "-p", "127.0.0.1::18080",
                           "-e", "PORT=18080", "-e", "APP_ENV=testing", "-e", "APP_DEBUG=false",
                           "-e", "APP_KEY=base64:" + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
                           "-e", "DB_CONNECTION=sqlite", "-e", "DB_DATABASE=/tmp/billing_test.sqlite",
                           "-e", "DB_URL=null", "-e", "CACHE_STORE=database", "-e", "SESSION_DRIVER=array", image)
        def public_base():
            mapping = json.loads(docker('inspect', '--format', '{{json .NetworkSettings.Ports}}', name))
            return 'http://127.0.0.1:' + mapping['18080/tcp'][0]['HostPort']

        base = public_base()

        def request(path, token=None):
            headers = {'Accept': 'application/json'}
            if token:
                headers['Authorization'] = 'Bearer ' + token
            try:
                with urllib.request.urlopen(urllib.request.Request(base + path, headers=headers), timeout=5) as r:
                    return r.status, r.read().decode()
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode()

        def wait():
            for _ in range(60):
                try:
                    if request('/up')[0] == 200:
                        return
                except (OSError, TimeoutError):
                    pass
                time.sleep(1)
            raise RuntimeError('Local container did not become healthy.')

        wait()
        assert request('/ready')[0] == 503, 'Startup must not migrate'
        for path in ['/composer.json', '/Dockerfile', '/.env']:
            assert request(path)[0] in (403, 404), 'Private files exposed'
        assert request('/api/user')[0] == 401
        assert request('/api/dashboard/financial')[0] == 401
        docker('exec', name, 'php', '-r', "if (file_exists('/tmp/billing_test.sqlite')) exit(1); touch('/tmp/billing_test.sqlite'); chmod('/tmp/billing_test.sqlite', 0666);")
        docker('exec', name, 'php', 'bin/migrate-isolated.php', '--apply-isolated')
        status, body = request('/ready')
        assert status == 200 and json.loads(body)['release_sha'] == sha
        seed = "require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $c=App\\Models\\Company::create(['name'=>'Container fixture']); $u=App\\Models\\User::forceCreate(['email_verified_at'=>now(),'name'=>'Fixture','email'=>'fixture@example.test','password'=>Illuminate\\Support\\Facades\\Hash::make('local-test-only'),'company_id'=>$c->id,'role'=>'admin']); Illuminate\\Support\\Facades\\Cache::put('smoke-marker','kept',600); echo $u->createToken('smoke')->plainTextToken;"
        token = docker('exec', name, 'php', '-r', seed)
        assert request('/api/user', token)[0] == 200, 'Sanctum Bearer header lost'
        status, body = request('/api/dashboard/financial', token)
        assert status == 200 and json.loads(body)['cache'] == 'disabled', 'Container financial SQL read failed'
        for resource in ['clients', 'products', 'invoices', 'quotes', 'payments']:
            status, body = request('/api/' + resource + '?per_page=25', token)
            payload = json.loads(body)
            assert status == 200 and payload['data'] == [] and payload['total'] == 0, 'Container paginated read failed'
        docker('restart', name)
        # Docker Desktop can allocate a different ephemeral host port on restart.
        base = public_base()
        wait()
        assert request('/ready')[0] == 200 and request('/api/user', token)[0] == 200
        check = "require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Illuminate\\Support\\Facades\\Cache::get('smoke-marker');"
        assert docker('exec', name, 'php', '-r', check) == 'kept', 'Restart purged shared cache'
        result = subprocess.run(['docker', 'exec', '-e', 'APP_ENV=production', name, 'php', 'bin/migrate-isolated.php', '--apply-isolated'], capture_output=True)
        assert result.returncode != 0, 'Production migration guard failed'
        print('Container HTTP, paginated/financial reads, dynamic port, readiness, Sanctum, private files, restart and migration guard: PASS')
    finally:
        if container and docker('inspect', '--format', '{{.Id}}', name) == container:
            docker('rm', '-f', '-v', name)


if __name__ == '__main__':
    try:
        main(sys.argv[1], sys.argv[2])
    except Exception:
        print('Local container smoke test failed; no production action performed.', file=sys.stderr)
        sys.exit(1)

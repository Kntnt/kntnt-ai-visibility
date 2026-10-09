"""Verify native deactivation removes persisted artifact rewrites, PHP 8.4 only."""

from http.client import RemoteDisconnected
import json
import os
from pathlib import Path
import re
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

from playground_process import stop_worker

ROOT = Path(__file__).resolve().parents[2]
PORT = int(os.environ.get('KNTNT_DEACTIVATION_PORT', '9451'))
OBSERVATIONS = 0
OWNED = {
    '^index\\.md$': 'index.php?markdown_request=1',
    '^(.+?)\\.md$': 'index.php?markdown_request=1',
    '^llms\\.txt$': 'index.php?kntnt_aiv_llms=index',
    '^llms-full\\.txt$': 'index.php?kntnt_aiv_llms=full',
}
OLD_PATHS = ['/index.md', '/ordinary.md', '/index/index.md', '/llms.txt', '/llms-full.txt']


class NoRedirect(HTTPRedirectHandler):
    """Expose stale homepage aliases instead of following them."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response."""
        return None


def request(base, path):
    """Read public status, headers and exact response bytes."""
    global OBSERVATIONS
    try:
        response = build_opener(NoRedirect).open(Request(base + path), timeout=25)
    except HTTPError as error:
        response = error
    with response:
        OBSERVATIONS += 1
        return response.status, response.headers, response.read().replace(b'\\_', b'_')


def control(base, action):
    """Invoke native WordPress APIs through the disposable MU fixture."""
    status, _, body = request(base, '/?deactivation_token=fixture-only&deactivation_action=' + action)
    assert status == 200, (action, status, body[:1000])
    return json.loads(body)


def owned_rules(state):
    """Recognise the literal marker variables, including an unexpected path."""
    return {
        pattern: target for pattern, target in (state['rules'] or {}).items()
        if re.search(r'(?:\?|&)(?:markdown_request|kntnt_aiv_llms)=', target)
    }


def routes(state, mode, active):
    """Require the actual complete final plugin set and native plain emptiness."""
    assert state['active'] is active, state
    assert owned_rules(state) == (OWNED if active and mode == 'pretty' else {}), state
    if mode == 'pretty':
        assert state['rules'].get('^fixture-keep$') == 'index.php?deactivation_fixture=keep', state
    else:
        assert state['rules'] == '', state  # Native refresh persists an empty string in plain mode.


def marker(role, version):
    """Literal expected source bodies come from the fixture's public content."""
    suffix = f'UPDATED-{version}' if role == 'ordinary' and version else 'ORIGINAL'
    return f'DEACTIVATION-{role}-{suffix}'.encode()


def forms(state, mode):
    """Supported alternates preserve final home/index and plain query contracts."""
    if mode == 'pretty':
        return {'home': '/index.md', 'ordinary': '/ordinary.md', 'index': '/index/index.md'}
    return {
        'home': '/?format=markdown',
        'ordinary': f"/?page_id={state['sources']['ordinary']}&format=markdown",
        'index': f"/?page_id={state['sources']['index']}&format=markdown",
    }


def artifacts(base, state, mode):
    """Cold and warmed source/aggregate bytes must agree with the current source."""
    for _ in range(2):
        for role, path in forms(state, mode).items():
            status, fields, body = request(base, path)
            assert status == 200 and fields.get_content_type() == 'text/markdown', (path, status, body[:1200])
            assert body.count(marker(role, state['version'])) == 1, (path, body[:1200])
            if role == 'ordinary' and state['version']:
                assert b'DEACTIVATION-ordinary-ORIGINAL' not in body, body[:1200]
        status, fields, body = request(base, '/llms.txt')
        assert status == 200 and fields.get_content_type() == 'text/plain', (status, body[:1200])
        for path in forms(state, mode).values():
            assert (base + path).encode() in body, (path, body)
        status, fields, body = request(base, '/llms-full.txt')
        assert status == 200 and fields.get_content_type() == 'text/plain', (status, body[:1200])
        for role in ['home', 'ordinary', 'index']:
            assert body.count(marker(role, state['version'])) == 1, (role, body[:1200])


def native_paths(base):
    """Observe each plain path with plugin unloaded and its cleanup bypassed."""
    observed = {}
    for path in OLD_PATHS:
        status, fields, body = request(base, path)
        assert fields.get_content_type() == 'text/html', (path, status, fields)
        observed[path] = (status, fields.get('Location'), marker('home', 0) in body)
    print('Native plugin-unloaded plain baseline: ' + repr(observed), flush=True)
    return observed


def inactive_http(base, state, mode, baseline):
    """Pretty paths become missing; native query sources keep their HTML meaning."""
    missing_status, missing_fields, missing_body = request(base, '/fixture-native-missing')
    for path in OLD_PATHS:
        status, fields, body = request(base, path)
        assert fields.get_content_type() not in ['text/markdown', 'text/plain'], (path, status, body[:1000])
        if mode == 'pretty':
            assert status == 404 and fields.get('Location') is None, (path, status, fields)
            assert marker('home', 0) not in body, (path, body[:1000])
        else:
            # Native canonical guessing differs per path in plain mode. Compare
            # the same path to the independent baseline with our cleanup bypassed.
            assert (status, fields.get('Location'), marker('home', 0) in body) == baseline[path], (path, status, fields, baseline[path])
    for role, path in forms(state, mode).items():
        if mode == 'pretty':
            path = {'home': '/', 'ordinary': '/ordinary/', 'index': '/index/'}[role]
        status, fields, body = request(base, path)
        assert status == 200 and fields.get_content_type() == 'text/html', (path, status, body[:1000])
        assert marker(role, state['version']) in body, (path, body[:1000])
        if role != 'home':
            assert marker('home', 0) not in body, (path, body[:1000])
    path = '/fixture-keep' if mode == 'pretty' else '/?deactivation_fixture=keep'
    assert request(base, path)[::2] == (200, b'UNRELATED-ROUTE'), path
    print(f'{mode}: inactive native missing-path status {missing_status}; HTML sources/unrelated route preserved.', flush=True)


def verify(base):
    """Cover both modules and native deactivation ordering in each permalink mode."""
    initial = control(base, 'state')
    assert re.fullmatch(r'8\.4\.[0-9]+', initial['php']), initial
    print('Actual Playground PHP ' + initial['php'] + '; ' + base, flush=True)
    settings = initial['settings']
    for mode in ['pretty', 'plain']:
        state = control(base, mode)
        baseline = None
        if mode == 'plain':
            native = control(base, 'native-baseline')
            routes(native, mode, False)
            baseline = native_paths(base)
            state = control(base, 'activate')
        for action in ['deactivate', 'deactivate-early', 'deactivate-bootstrap']:
            routes(state, mode, True)
            artifacts(base, state, mode)
            inactive = control(base, action)
            routes(inactive, mode, False)
            assert inactive['settings'] == settings, inactive
            inactive_http(base, inactive, mode, baseline)
            changed = control(base, 'update-inactive')
            assert changed['active'] is False and changed['version'] == state['version'] + 1, changed
            state = control(base, 'activate')
            routes(state, mode, True)
            assert state['settings'] == settings and state['version'] == changed['version'], state
            artifacts(base, state, mode)
            print(f'PASS {mode}/{action}: owned rules gone, settings retained, cache cleared, reactivation current.', flush=True)
        if mode == 'pretty':
            # Ownership is pattern AND target: replacing a pattern must survive.
            overlap = control(base, 'deactivate-overlap')
            assert overlap['active'] is False and owned_rules(overlap) == {}, overlap
            for pattern in ['^index\\.md$', '^llms\\.txt$']:
                assert overlap['rules'][pattern] == 'index.php?deactivation_fixture=keep', overlap
            for path in ['/index.md', '/llms.txt', '/fixture-keep']:
                assert request(base, path)[::2] == (200, b'UNRELATED-ROUTE'), path
            state = control(base, 'activate')
            routes(state, mode, True)
            artifacts(base, state, mode)
            print('PASS independent replacements of Markdown/llms patterns retained; reactivation restores owned routes.', flush=True)
    print('Deactivation HTTP: 0 failures; ' + base, flush=True)


def run(subpath):
    """Record the isolated worker immediately and always stop its process group."""
    base = f'http://127.0.0.1:{PORT}{subpath}'
    worker = subprocess.Popen([
        'npx', '--yes', '@wp-playground/cli@3.1.36', 'server', '--php=8.4', '--wp=latest',
        '--workers=1', f'--port={PORT}', f'--site-url={base}',
        f'--mount={ROOT}:/wordpress/wp-content/plugins/kntnt-ai-visibility',
        f'--blueprint={ROOT}/tests/Integration/deactivation-blueprint.json',
    ], start_new_session=True)
    try:
        tracker = Path.home() / '.agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py'
        if tracker.exists():
            subprocess.run(['uv', 'run', str(tracker), 'add', 'pid', str(worker.pid), '#31 native deactivation Playground'], check=True)
        print(f'Playground process group {worker.pid}', flush=True)
        for _ in range(90):
            if worker.poll() is not None:
                raise RuntimeError('Playground exited during setup')
            try:
                if control(base, 'state').get('sources'):
                    break
            except (URLError, RemoteDisconnected, TimeoutError, AssertionError, json.JSONDecodeError):
                pass
            time.sleep(2)
        else:
            raise RuntimeError('Deactivation fixture readiness timeout')
        verify(base)
    finally:
        stop_worker(worker)
        print(f'Stopped Playground process group {worker.pid}', flush=True)


for installation in ['', '/sub']:
    run(installation)
print(f'Deactivation total: 0 failures; {OBSERVATIONS} observed responses including native controls.', flush=True)

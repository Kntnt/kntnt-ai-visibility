"""Verify exact and safe canonical links through real WordPress HTTP responses.

KNTNT_CANONICAL_LINKS_PORT selects a worker, default9443. Every worker owns a
tracked process group and is stopped even when a regression assertion fails.
"""

from http.client import RemoteDisconnected
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request, urlopen

from playground_process import stop_worker


def request(base, path, method='GET', headers=None):
    """Read actual response metadata, including deliberate bodyless304 replies."""
    try:
        response = urlopen(Request(base + path, method=method, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def control(base, action):
    """Change only fixture-owned metadata or native permalink settings."""
    status, _, body = request(base, '/?canonical_links_token=fixture-only&canonical_links_action=' + action)
    assert status == 200, (action, status, body[:1200])
    return json.loads(body)


def link(fields, expected):
    """Require one exact canonical, including the source's query ordering."""
    values = re.findall(r'<([^>]+)>; rel="canonical"', ' '.join(fields.get_all('Link', [])))
    assert values == [expected], (expected, values)


def same_url(actual, expected):
    """Compare independent literal native expectations without query ordering."""
    left, right = urlsplit(actual), urlsplit(expected)
    return (left.scheme, left.netloc, left.path, parse_qs(left.query)) == (right.scheme, right.netloc, right.path, parse_qs(right.query))


def expected_urls(base, ids, mode, explicit, locale, role):
    """Supply known native URL policies, independent of router key decoding."""
    if mode == 'plain':
        query = {} if role == 'front' else {'p' if role == 'post' else 'page_id': ids[locale][role]}
        if locale == 'sv_SE' or explicit:
            query['lang'] = 'sv' if locale == 'sv_SE' else 'en'
        canonical = base + '/' + ('?' + urlencode(query) if query else '')
        return canonical, base + '/?' + urlencode({**query, 'format': 'markdown'})
    prefix = '/sv' if locale == 'sv_SE' else '/en' if explicit else ''
    path = {'front': '/', 'index': '/index', 'nested': '/index/index', 'post': '/plain-story'}[role]
    if mode == 'slash' and role != 'front':
        path += '/'
    alternate = {'front': '/index.md', 'index': '/index/index.md', 'nested': '/index/index/index.md', 'post': '/plain-story.md'}[role]
    return base + prefix + path, base + prefix + alternate


def verify(base):
    """Verify actual cold/warm/HEAD/304 canonical and unsafe metadata handling."""
    state = control(base, 'state')
    assert re.fullmatch(r'8\.4\.[0-9]+', state['php']), state
    print(f"Actual canonical-link Playground PHP {state['php']}, home {base}", flush=True)
    control(base, 'flush')
    status, cold_fields, _ = request(base, '/index/index.md')
    expected = base + '/index'
    assert status == 200, cold_fields
    link(cold_fields, expected)
    large = control(base, 'large')
    assert large['size'] > 65536 and large['canonical'] == base + '/index', large
    status, warm_fields, _ = request(base, '/index/index.md')
    assert status == 200, (large, warm_fields)
    link(warm_fields, expected)
    for method, conditions, expected_status in [('HEAD', {}, 200), ('GET', {'If-None-Match': warm_fields['ETag']}, 304), ('GET', {'If-Modified-Since': warm_fields['Last-Modified']}, 304)]:
        status, fields, body = request(base, '/index/index.md', method, conditions)
        assert status == expected_status and body == b'', (status, fields, body[:1200])
        link(fields, expected)
    print(f"PASS large actual Front_Matter serialisation: {large['size']} bytes; cold/warm/HEAD/ETag/date links agree; body metadata ignored.", flush=True)
    fixtures = ['missing', 'unterminated', 'duplicate', 'external', 'origin-prefix', 'injection', 'malformed', 'non-string', 'quote', 'del', 'body-only', 'dot-segment']
    if base.endswith('/sub'):
        fixtures += ['outside-base']
    for fixture in fixtures:
        control(base, fixture)
        status, fields, body = request(base, '/index/index.md')
        assert status == 200 and fields.get_content_type() == 'text/markdown', (status, fields, body)
        assert 'rel="canonical"' not in ' '.join(fields.get_all('Link', [])), (fixture, fields)
        assert fields.get('X-Injected') is None, (fixture, fields)
        for method, conditions, expected_status in [('HEAD', {}, 200), ('GET', {'If-None-Match': fields['ETag']}, 304)]:
            status, response_fields, body = request(base, '/index/index.md', method, conditions)
            assert status == expected_status and body == b'', (fixture, status, body[:1200])
            assert 'rel="canonical"' not in ' '.join(response_fields.get_all('Link', [])), (fixture, response_fields)
            assert response_fields.get('X-Injected') is None, (fixture, response_fields)
    print('PASS unsafe/missing metadata: GET/HEAD/304 omit hints; no injected headers or invented fallback.', flush=True)

    for mode, explicit in [('slash', False), ('noslash', False), ('noslash', True), ('plain', True)]:
        control(base, 'explicit' if explicit else 'implicit')
        control(base, mode)
        control(base, 'flush')
        sources = control(base, 'sources')
        for locale, roles in sources.items():
            for role, source in roles.items():
                canonical, alternate = expected_urls(base, state['ids'], mode, explicit, locale, role)
                assert same_url(source['canonical'], canonical), (mode, locale, role, source, canonical)
                assert same_url(source['alternate'], alternate), (mode, locale, role, source, alternate)
                canonical = source['canonical']
                path = source['alternate'][len(base):]
                first_method = 'HEAD' if role == 'index' else 'GET'
                status, fields, body = request(base, path, first_method)
                assert status == 200 and fields.get_content_type() == 'text/markdown', (path, status, fields, body[:1200])
                link(fields, canonical)
                if first_method == 'HEAD':
                    assert body == b'', (path, body[:1200])
                status, fields, body = request(base, path)
                assert status == 200, (path, status, fields)
                link(fields, canonical)
                marker = f'BODY-{role}-{locale}'.encode()
                assert body.replace(b'\\_', b'_').count(marker) == 1, (path, marker, body[:1200])
                metadata = re.findall(rb'^canonical_url: (.+)$', body, re.M)
                assert len(metadata) == 1 and json.loads(metadata[0]) == canonical, (path, metadata, canonical)
                for method, conditions, expected_status in [('HEAD', {}, 200), ('GET', {'If-None-Match': fields['ETag']}, 304), ('GET', {'If-Modified-Since': fields['Last-Modified']}, 304)]:
                    status, response_fields, body = request(base, path, method, conditions)
                    assert status == expected_status and body == b'', (path, status, body[:1200])
                    link(response_fields, canonical)
                if mode == 'plain':
                    canonical_path = canonical[len(base):]
                    status, fields, _ = request(base, canonical_path, headers={'Accept': 'text/markdown'})
                    assert status == 200 and 'Last-Modified' not in fields, (canonical, status, fields)
                    link(fields, canonical)
                    status, fields, body = request(base, canonical_path, 'HEAD', {'Accept': 'text/markdown'})
                    assert status == 200 and body == b'', (canonical, status, body[:1200])
                    link(fields, canonical)
                    status, fields, body = request(base, canonical_path, headers={'Accept': 'text/markdown', 'If-None-Match': fields['ETag']})
                    assert status == 304 and body == b'', (canonical, status, body[:1200])
                    link(fields, canonical)
        print(f"PASS {mode}, {'explicit' if explicit else 'implicit'} languages: eight exact native WordPress canonicals across cold/warm/HEAD/ETag/date responses.", flush=True)
    print('Canonical links HTTP: 0 failures', flush=True)


def run(subpath):
    """Boot one recorded process group and stop all descendants on every exit."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get('KNTNT_CANONICAL_LINKS_PORT', '9443')
    base = f'http://127.0.0.1:{port}{subpath}'
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            'npx', '--yes', '@wp-playground/cli@3.1.36', 'server', '--php=8.4', '--wp=latest', '--workers=1',
            f'--port={port}', f'--site-url={base}', f'--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility',
            f'--blueprint={root}/tests/Integration/canonical-links-blueprint.json',
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / '.agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py'
            if tracker.exists():
                subprocess.run(['uv', 'run', str(tracker), 'add', 'pid', str(worker.pid), '#23 exact canonical links Playground'], check=True, stdout=subprocess.DEVNULL)
            print(f'Playground process group {worker.pid}', flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError('Playground exited during fixture setup')
                try:
                    if control(base, 'state').get('ids'):
                        break
                except (URLError, RemoteDisconnected, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError('Canonical-link fixture did not become ready')
            verify(base)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors='replace'), flush=True)
            raise
        finally:
            stop_worker(worker)


if __name__ == '__main__':
    for installation in ['', '/sub']:
        run(installation)

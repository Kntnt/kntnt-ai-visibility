"""Verify render/revocation publication through native WordPress HTTP.

KNTNT_PUBLICATION_PORT selects the port; KNTNT_PUBLICATION_PLUGIN_ROOT can mount an
isolated historical checkout for RED evidence. KNTNT_PUBLICATION_CASES selects
the plain-front or sql tracer; KNTNT_PUBLICATION_ROOT_ONLY selects the root base.
Worker groups are always stopped.
"""

import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener


from http_fixture import raw_head


class NoRedirect(HTTPRedirectHandler):
    """Expose stale addresses rather than following WordPress's guessed URL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response for independent identity assertions."""
        return None


def request(base, path, headers=None, method="GET"):
    """Read actual status, headers and source bytes from the public endpoint."""
    if method == "HEAD":
        return raw_head(base + path, headers, timeout=20)
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}, method=method), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().replace(b"\\_", b"_")


def control(base, action):
    """Read fixture readiness and actual PHP version."""
    status, _, body = request(base, "/?publication_token=fixture-only&publication_action=" + action)
    assert status == 200, (status, body[:1200])
    return json.loads(body)


def verify(base, ids):
    """Revoked producer bytes must never become a public response or artifact."""
    assert ids["php"].startswith("8.4."), ids
    print("Actual Playground PHP: " + ids["php"], flush=True)
    if os.environ.get("KNTNT_PUBLICATION_CASES") == "sql":
        verify_sql(base)
        return
    if os.environ.get("KNTNT_PUBLICATION_CASES") == "plain-front":
        queued = control(base, "queued-plain-front-cold")
        assert queued["refused"] and queued["restored"], queued
        print("Queued plain front canonical: stale work refused", flush=True)
        return
    for attempt in range(2):
        status, _, body = request(base, "/publication-draft.md")
        print(f"Draft revoke attempt {attempt}: HTTP {status}", flush=True)
        assert status != 200 and b"REVOKED-DURING-RENDER" not in body, (status, body)
    control(base, "queue")
    status, _, body = request(base, "/llms-full.txt")
    assert status != 200 and b"OBSOLETE-QUEUED-SOURCE" not in body, (status, body)
    status, _, body = request(base, "/publication-queued.md")
    print(f"Queued old source after rename: HTTP {status}", flush=True)
    assert status == 404 and b"OBSOLETE-QUEUED-SOURCE" not in body, (status, body)
    status, _, body = request(base, "/publication-current.md")
    assert status == 200 and body.count(b"CURRENT-SOURCE") == 1, (status, body)
    version = control(base, "version")
    assert version["current"] == version["before"] + 7, version
    assert version["after"] == version["before"] + 8, version
    for case in ["password", "exclusion", "whole", "inline", "head", "conditional"]:
        control(base, case)
        path = f"/publication-{case}/" if case == "inline" else f"/publication-{case}.md"
        fields = {"Accept": "text/markdown"} if case == "inline" else {}
        if case == "conditional":
            fields["If-None-Match"] = "*"
        status, headers, body = request(base, path, fields, "HEAD" if case == "head" else "GET")
        print(f"{case} during render: HTTP {status}", flush=True)
        assert status == 403 and b"REVOKED-DURING-RENDER" not in body, (case, status, body)
        assert "no-store" in headers.get("Cache-Control", ""), (case, headers)
        assert headers.get("ETag") is None and headers.get("X-Content-Type-Options") == "nosniff", headers
        if case == "head":
            assert body == b"", body
        if case != "whole":
            status, _, body = request(base, f"/publication-{case}.md")
            assert status in [403, 404] and b"REVOKED-DURING-RENDER" not in body, (case, status, body)
    for final in ["final-full", "final-index", "final-head"]:
        control(base, final)
        path = "/llms.txt" if final == "final-index" else "/llms-full.txt"
        status, headers, body = request(base, path, {"If-None-Match": "*"}, "HEAD" if final == "final-head" else "GET")
        assert status == 403 and b"REVOKED-FINAL" not in body, (final, status, body[:1200])
        assert "no-store" in headers.get("Cache-Control", "") and headers.get("ETag") is None, headers
        if final == "final-head":
            assert body == b"", body
        status, _, body = request(base, path)
        assert status == 200 and ("REVOKED-FINAL-" + final).encode() not in body, (final, status, body[:1200])
        status, _, body = request(base, "/publication-" + final + ".md")
        assert status in [403, 404] and ("REVOKED-FINAL-" + final).encode() not in body, (final, status, body[:1200])
        print(f"{final}: outer aggregate refused, retry excludes revoked source", flush=True)
    for mode in ["pretty", "plain"]:
        for case in ["body", "rename", "parent", "front"]:
            # Plain parent/front changes leave the numeric source identity valid.
            if mode == "plain" and case == "parent":
                continue
            for temperature in ["cold", "warm"]:
                queued = control(base, f"queued-{mode}-{case}-{temperature}")
                assert queued["refused"] and queued["restored"], queued
                old = urlsplit(queued["old"])
                current = urlsplit(queued["current"])
                origin = base.split("/sub")[0]
                if queued["old"] != queued["current"]:
                    status, old_headers, body = request(origin, old.path + ("?" + old.query if old.query else ""))
                    assert old_headers.get_content_type() != "text/markdown", (queued, status, body[:1000])
                    if mode == "pretty":
                        assert status == 404, (queued, status, body[:1000])
                status, headers, body = request(origin, current.path + ("?" + current.query if current.query else ""))
                marker = b"CURRENT-QUEUED-BODY" if case in ["body", "rename"] else b"OBSOLETE-QUEUED-BODY"
                assert status == 200 and headers.get_content_type() == "text/markdown", (queued, status, body[:1000])
                assert body.count(marker) == 1, (queued, body)
                print(f"Queued {mode}/{case}/{temperature}: stale source refused, current source intact", flush=True)
    verify_sql(base)
    print("Publication HTTP: 0 failures", flush=True)


def verify_sql(base):
    """Fail only the real version SELECT before either HTTP refusal shell."""
    failures = []
    for policy in ["quiet", "visible"]:
        for result in ["success", "failure"]:
            state = control(base, f"sql-policy-{policy}-{result}")
            assert state["before"] == state["after"] and state["debug"] and state["display"], state
            assert state["refused"] == (result == "failure"), state
            print(f"SQL policy {policy}/{result}: exact restoration, debug/display enabled", flush=True)
    for phase in ["early", "late"]:
        for temperature in ["cold", "warm"]:
            # Warm exact paths exit in the early router before the late fault.
            if phase == "late" and temperature == "warm":
                continue
            for path in ["/llms.txt", "/llms-full.txt"]:
                control(base, "sql-prepare")
                if temperature == "warm":
                    status, _, body = request(base, path)
                    assert status == 200 and b"CURRENT-SQL-SOURCE" in body, (status, body[:1200])
                for method in ["GET", "HEAD", "conditional"]:
                    fields = {"X-Publication-Sql-Failure": phase}
                    if method == "conditional":
                        fields["If-None-Match"] = "*"
                    status, headers, body = request(base, path, fields, "HEAD" if method == "HEAD" else "GET")
                    okay = (status == 403 and headers.get_content_type() == "text/plain"
                            and "no-store" in headers.get("Cache-Control", "")
                            and headers.get("X-Content-Type-Options") == "nosniff"
                            and headers.get("ETag") is None
                            and headers.get("Last-Modified") is None and headers.get("Link") is None
                            and b"CURRENT-SQL-SOURCE" not in body
                            and b"SELECT" not in body and b"database error" not in body
                            and (method != "HEAD" or body == b""))
                    label = f"SQL {phase}/{temperature}/{path}/{method}"
                    print(f"{label}: HTTP {status}, controlled={okay}, body={body[:180]!r}", flush=True)
                    if not okay:
                        failures.append(label)
                        if os.environ.get("KNTNT_PUBLICATION_SQL_FIRST") == "1":
                            raise AssertionError((label, status, body[:1200]))
                status, _, body = request(base, path)
                assert status == 200 and b"CURRENT-SQL-SOURCE" in body, (status, body[:1200])
    for translation, expected in [("loaded", b"FIXTURE-LOADED-TRANSLATION"),
                                  ("gettext", b"FIXTURE-DOMAIN-TRANSLATION")]:
        for path in ["/llms.txt", "/llms-full.txt"]:
            control(base, "sql-prepare")
            for method in ["GET", "HEAD", "conditional"]:
                fields = {"X-Publication-Sql-Failure": "early", "X-Publication-Translation": translation}
                if method == "conditional":
                    fields["If-None-Match"] = "*"
                status, headers, body = request(base, path, fields, "HEAD" if method == "HEAD" else "GET")
                okay = (status == 403 and headers.get_content_type() == "text/plain"
                        and "no-store" in headers.get("Cache-Control", "")
                        and headers.get("X-Content-Type-Options") == "nosniff"
                        and all(headers.get(name) is None for name in ["ETag", "Last-Modified", "Link"])
                        and body == (b"" if method == "HEAD" else expected))
                label = f"Early translation {translation}/{path}/{method}"
                print(f"{label}: HTTP {status}, controlled={okay}, body={body[:180]!r}", flush=True)
                if not okay:
                    failures.append(label)
    assert not failures, failures
    print("SQL failure HTTP: 0 failures", flush=True)


def run(subpath):
    """Run one disposable installation and stop its recorded process group."""
    root = Path(os.environ.get("KNTNT_PUBLICATION_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_PUBLICATION_PORT", "9439")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/publication-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(
                    ["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#9 publication barrier Playground"],
                    check=True, stdout=subprocess.DEVNULL,
                )
            print(f"Playground process group: {worker.pid}", flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    ids = control(base, "state")
                    if ids.get("ready"):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Publication fixtures never became ready")
            verify(base, ids)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            try:
                os.killpg(worker.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                worker.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(worker.pid, signal.SIGKILL)
                worker.wait()


if __name__ == "__main__":
    installations = [""] if os.environ.get("KNTNT_PUBLICATION_ROOT_ONLY") == "1" else ["", "/sub"]
    for installation in installations:
        run(installation)

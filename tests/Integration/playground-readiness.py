"""Check real HTTP disconnects at the Playground startup boundary.

Run with Python 3. A closing fixture must be retried during startup, while
transport failures in the subsequent verification must still escape.
"""

from contextlib import redirect_stdout
from http.client import RemoteDisconnected
from http.server import BaseHTTPRequestHandler, HTTPServer
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


class ClosingFixture(BaseHTTPRequestHandler):
    """Close the first real connection, then identify the fresh fixture."""

    def do_GET(self):
        """Expose the shutdown/startup race without replacing the HTTP client."""
        self.server.paths.append(self.path)
        if len(self.server.paths) == 1:
            self.close_connection = True
            return
        body = json.dumps({"php": "8.4.20", "sources": [1, 2, 3, 4, 5, 6], "ids": [1]}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        """Keep fixture traffic out of the assertion output."""
        return


class ReadinessTests(unittest.TestCase):
    """Exercise the two failing callers at their actual startup HTTP seam."""

    def exercise(self, filename, port_key, verification_error=False, without_tracker=False):
        """Keep the real request/control functions and isolate worker ownership."""
        path = Path(__file__).with_name(filename)
        spec = importlib.util.spec_from_file_location("readiness_subject", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        server = HTTPServer(("127.0.0.1", 0), ClosingFixture)
        server.paths = []
        thread = threading.Thread(target=server.serve_forever, kwargs={"poll_interval": 0.01})
        thread.start()
        port = server.server_address[1]
        worker = SimpleNamespace(pid=1, poll=lambda: None, wait=lambda timeout: 0)
        verify = Mock(side_effect=RemoteDisconnected("verification disconnect") if verification_error else None)
        stop = Mock()
        environment = {port_key: str(port)}
        owned_os = SimpleNamespace(environ=environment, killpg=Mock())
        command = Mock(side_effect=FileNotFoundError("uv") if without_tracker else None)
        owned_subprocess = SimpleNamespace(Popen=Mock(return_value=worker), run=command, STDOUT=subprocess.STDOUT, DEVNULL=subprocess.DEVNULL, TimeoutExpired=subprocess.TimeoutExpired)
        owned_path = Mock(wraps=Path)
        owned_path.home.return_value = Path("/tmp/kntnt-ai-visibility-no-personal-tracker")
        try:
            with patch.object(module, "os", owned_os), patch.object(module, "subprocess", owned_subprocess), patch.object(module, "time", SimpleNamespace(sleep=Mock())), patch.object(module, "tempfile", SimpleNamespace(TemporaryFile=io.BytesIO)), patch.object(module, "verify", verify), patch.object(module, "stop_worker", stop), patch.object(module, "Path", owned_path) if without_tracker else patch.object(module, "Path", Path), redirect_stdout(io.StringIO()):
                if verification_error:
                    with self.assertRaisesRegex(RemoteDisconnected, "verification disconnect"):
                        module.run("/sub")
                else:
                    module.run("/sub")
            self.assertEqual(len(server.paths), 2)
            self.assertTrue(all(path.startswith("/sub/") for path in server.paths))
            verify.assert_called_once_with(f"http://127.0.0.1:{port}/sub")
            stop.assert_called_once()
            self.assertIs(stop.call_args.args[0], worker)
            if without_tracker:
                command.assert_not_called()
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_missing_personal_tracker_does_not_require_uv(self):
        """A portable runner must boot without the maintainer's private tool."""
        self.exercise("playground-canonical-links.py", "KNTNT_CANONICAL_LINKS_PORT", without_tracker=True)

    def test_server_child_stops_after_launcher_exits(self):
        """A terminated launcher must not leave its listening child alive."""
        spec = importlib.util.spec_from_file_location("owned_worker_subject", Path(__file__).with_name("playground-indirect.py"))
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        child = """import json,signal,sys
from http.server import BaseHTTPRequestHandler,HTTPServer
signal.signal(signal.SIGTERM,signal.SIG_IGN)
class Handler(BaseHTTPRequestHandler):
 def do_GET(self):
  body=json.dumps({"php":"8.4.20"}).encode()
  self.send_response(200)
  self.send_header("Content-Type","application/json")
  self.send_header("Content-Length",str(len(body)))
  self.end_headers()
  self.wfile.write(body)
 def log_message(self,*args): pass
HTTPServer(("127.0.0.1",int(sys.argv[1])),Handler).serve_forever()
"""
        parent = "import subprocess,sys,time; subprocess.Popen([sys.executable,'-c',sys.argv[1],sys.argv[2]]); time.sleep(60)"
        workers = []

        def start_worker(*args, **kwargs):
            """Create the same owned process-group shape as npm and its CLI child."""
            worker = subprocess.Popen([sys.executable, "-c", parent, child, str(port)], **kwargs)
            workers.append(worker)
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(worker.pid), "Release readiness regression: owned launcher and HTTP child"], check=True, stdout=subprocess.DEVNULL)
            return worker

        verify = Mock()
        owned_subprocess = SimpleNamespace(Popen=start_worker, run=Mock(), STDOUT=subprocess.STDOUT, DEVNULL=subprocess.DEVNULL, TimeoutExpired=subprocess.TimeoutExpired)
        owned_os = SimpleNamespace(environ={"KNTNT_INDIRECT_PORT": str(port)}, killpg=os.killpg)
        try:
            with patch.object(module, "os", owned_os), patch.object(module, "subprocess", owned_subprocess), patch.object(module, "verify", verify), redirect_stdout(io.StringIO()):
                module.run("/sub")
            verify.assert_called_once_with(f"http://127.0.0.1:{port}/sub")
            self.assertIsNotNone(workers[0].returncode)
            with socket.socket() as probe:
                self.assertNotEqual(probe.connect_ex(("127.0.0.1", port)), 0, "The launcher exited but its HTTP child is still listening")
        finally:
            for worker in workers:
                try:
                    os.killpg(worker.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                worker.wait(timeout=5)

    def test_relative_fixture_retries_only_startup_disconnect(self):
        """An EOF from the closing worker must not abort subdirectory startup."""
        self.exercise("playground-relative-references.py", "KNTNT_REFERENCES_PORT")

    def test_indirect_fixture_retries_only_startup_disconnect(self):
        """The second installation must reach its fresh JSON fixture."""
        self.exercise("playground-indirect.py", "KNTNT_INDIRECT_PORT")

    def test_relative_verification_disconnect_escapes(self):
        """After readiness, the same transport error must fail verification."""
        self.exercise("playground-relative-references.py", "KNTNT_REFERENCES_PORT", True)

    def test_indirect_verification_disconnect_escapes(self):
        """Startup retries must not hide a failed artifact probe."""
        self.exercise("playground-indirect.py", "KNTNT_INDIRECT_PORT", True)


if __name__ == "__main__":
    unittest.main()

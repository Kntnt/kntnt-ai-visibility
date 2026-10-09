"""Stop an owned Playground group even when npm exits before its CLI child.

Call only for a worker started with start_new_session=True. The complete group
gets a bounded grace period before SIGKILL; the launcher is always reaped.
"""

import os
import signal
import time


def stop_worker(worker, grace_seconds=5):
    """Bound the whole group's lifetime rather than only the npm launcher's."""
    # Ask every owned process to stop, including children of an exited launcher.
    try:
        os.killpg(worker.pid, signal.SIGTERM)
    except ProcessLookupError:
        worker.wait(timeout=grace_seconds)
        return

    # Reap the launcher while checking whether its complete group has gone.
    deadline = time.monotonic() + grace_seconds
    while True:
        worker.poll()
        try:
            os.killpg(worker.pid, 0)
        except ProcessLookupError:
            break
        if time.monotonic() >= deadline:
            try:
                os.killpg(worker.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            break
        time.sleep(0.05)

    worker.wait(timeout=grace_seconds)

    # SIGKILL is asynchronous; wait for child exit before releasing its port.
    deadline = time.monotonic() + grace_seconds
    while True:
        try:
            os.killpg(worker.pid, 0)
        except ProcessLookupError:
            return
        if time.monotonic() >= deadline:
            raise RuntimeError(f"Playground process group {worker.pid} did not stop")
        time.sleep(0.05)

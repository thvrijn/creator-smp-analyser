"""Checks in with the app (heartbeat), so the app knows this worker is online and can send it jobs.

Only runs when WORKER_APP_URL is set. Every HEARTBEAT_SECONDS the worker posts its name, the URL the app
reaches it on, its GPU and whether it is busy to POST {WORKER_APP_URL}/api/workers/heartbeat. On shutdown
(SIGTERM/SIGINT) it signs off, so the app sees it go offline at once instead of after the online window.
"""
import json
import os
import signal
import socket
import sys
import threading
import time
import urllib.error
import urllib.request
from contextlib import contextmanager
from functools import lru_cache
from typing import Any, Callable, Iterator

HEARTBEAT_SECONDS = 15
TASKS = ("transcribe", "extract")
DEFAULT_PRIORITY = {"cuda": 100, "mlx": 50, "cpu": 10}


class ActiveTasks:
    """Counts the requests being processed, so the heartbeat can report busy."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._count = 0

    @contextmanager
    def track(self) -> Iterator[None]:
        with self._lock:
            self._count += 1
        try:
            yield
        finally:
            with self._lock:
                self._count -= 1

    @property
    def busy(self) -> bool:
        return self._count > 0


ACTIVE = ActiveTasks()


def app_url() -> str:
    return os.getenv("WORKER_APP_URL", "").rstrip("/")


def token() -> str:
    return os.getenv("WORKER_TOKEN", "")


def worker_name() -> str:
    return os.getenv("WORKER_NAME") or socket.gethostname()


def backend() -> str:
    if "mlx" in (os.getenv("EVENT_DEVICE"), os.getenv("WHISPER_DEVICE")):
        return "mlx"
    if "cuda" in (os.getenv("EVENT_DEVICE", "cuda"), os.getenv("WHISPER_DEVICE", "cpu")):
        return "cuda"
    return "cpu"


def capabilities() -> list[str]:
    wanted = [task.strip() for task in os.getenv("WORKER_CAPABILITIES", ",".join(TASKS)).split(",")]
    return [task for task in TASKS if task in wanted]


@lru_cache(maxsize=1)
def gpu_info() -> tuple[str | None, int | None]:
    """The GPU's name and memory in MB, looked up once."""
    try:
        if backend() == "mlx":
            import mlx.core as mx

            info = mx.device_info()
            return str(info["device_name"]), int(info["memory_size"]) // 2**20
        if backend() == "cuda":
            import torch

            if torch.cuda.is_available():
                return torch.cuda.get_device_name(0), torch.cuda.get_device_properties(0).total_memory // 2**20
    except Exception as exc:  # noqa: BLE001 - GPU info is informational only
        print(f"registration: GPU info unavailable: {exc}", flush=True)
    return None, None


def heartbeat_payload(port: int, loaded_model: str | None) -> dict[str, Any]:
    gpu_name, vram_mb = gpu_info()
    kind = backend()
    return {
        "name": worker_name(),
        "url": os.getenv("WORKER_PUBLIC_URL") or f"http://{socket.gethostname()}:{port}",
        "backend": kind,
        "gpu_name": gpu_name,
        "vram_mb": vram_mb,
        "capabilities": capabilities(),
        "priority": int(os.getenv("WORKER_PRIORITY") or DEFAULT_PRIORITY[kind]),
        "loaded_model": loaded_model,
        "busy": ACTIVE.busy,
    }


def post(path: str, payload: dict[str, Any], timeout: float = 10) -> None:
    request = urllib.request.Request(
        app_url() + path,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json", "Accept": "application/json", "Authorization": f"Bearer {token()}"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        response.read()


def _heartbeat_loop(port: int, loaded_model: Callable[[], str | None]) -> None:
    registered: bool | None = None
    while True:
        try:
            post("/api/workers/heartbeat", heartbeat_payload(port, loaded_model()))
            if registered is not True:
                print(f"registration: checked in with {app_url()} as {worker_name()}", flush=True)
            registered = True
        except (urllib.error.URLError, OSError, ValueError) as exc:
            # Log only changes, so an app that is down does not flood the log.
            if registered is not False:
                detail = exc.read().decode("utf-8", "replace")[:200] if isinstance(exc, urllib.error.HTTPError) else ""
                print(f"registration: cannot reach {app_url()}: {exc} {detail}".rstrip(), flush=True)
            registered = False
        time.sleep(HEARTBEAT_SECONDS)


def sign_off() -> None:
    try:
        post("/api/workers/offline", {"name": worker_name()}, timeout=5)
        print("registration: signed off", flush=True)
    except (urllib.error.URLError, OSError) as exc:
        print(f"registration: sign-off failed: {exc}", flush=True)


def start(port: int, loaded_model: Callable[[], str | None]) -> bool:
    """Starts the heartbeat thread and the sign-off on shutdown. Returns False when WORKER_APP_URL is not set."""
    if not app_url():
        print("registration: WORKER_APP_URL not set, not checking in with an app", flush=True)
        return False
    if not token():
        print("registration: WORKER_TOKEN not set; the app will refuse the heartbeat", flush=True)

    def stop(signum: int, _frame: Any) -> None:
        sign_off()
        sys.exit(128 + signum)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    threading.Thread(target=_heartbeat_loop, args=(port, loaded_model), daemon=True).start()
    return True

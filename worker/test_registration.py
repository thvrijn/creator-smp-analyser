import json
import os
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

import registration


class RegistrationTest(unittest.TestCase):
    def setUp(self) -> None:
        gpu = patch.object(registration, "gpu_info", return_value=("NVIDIA GeForce RTX 4080", 16376))
        gpu.start()
        self.addCleanup(gpu.stop)

    def test_heartbeat_payload_describes_the_worker(self) -> None:
        env = {"WORKER_NAME": "pc-4080", "WORKER_PUBLIC_URL": "http://pc:8001", "EVENT_DEVICE": "cuda", "WHISPER_DEVICE": "cuda"}
        with patch.dict(os.environ, env, clear=True):
            payload = registration.heartbeat_payload(8001, "whisper")
        self.assertEqual(payload, {
            "name": "pc-4080",
            "url": "http://pc:8001",
            "backend": "cuda",
            "gpu_name": "NVIDIA GeForce RTX 4080",
            "vram_mb": 16376,
            "capabilities": ["transcribe", "extract"],
            "priority": 100,
            "loaded_model": "whisper",
            "busy": False,
        })

    def test_mac_gets_a_lower_default_priority_and_capabilities_can_be_limited(self) -> None:
        with patch.dict(os.environ, {"EVENT_DEVICE": "mlx", "WORKER_CAPABILITIES": "extract, bogus"}, clear=True):
            payload = registration.heartbeat_payload(8001, None)
        self.assertEqual((payload["backend"], payload["priority"], payload["capabilities"]), ("mlx", 50, ["extract"]))

    def test_busy_while_a_task_is_tracked(self) -> None:
        tasks = registration.ActiveTasks()
        with tasks.track():
            self.assertTrue(tasks.busy)
        self.assertFalse(tasks.busy)

    def test_without_app_url_nothing_is_started(self) -> None:
        with patch.dict(os.environ, {}, clear=True):
            self.assertFalse(registration.start(8001, lambda: None))

    def test_heartbeat_is_posted_with_the_token(self) -> None:
        received: list[tuple[str, str, dict]] = []

        class App(BaseHTTPRequestHandler):
            def do_POST(self) -> None:  # noqa: N802
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                received.append((self.path, self.headers["Authorization"], body))
                self.send_response(200)
                self.send_header("Content-Length", "2")
                self.end_headers()
                self.wfile.write(b"{}")

            def log_message(self, *_args) -> None:
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), App)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        env = {"WORKER_APP_URL": f"http://127.0.0.1:{server.server_address[1]}/", "WORKER_TOKEN": "secret", "WORKER_NAME": "laptop"}
        with patch.dict(os.environ, env, clear=True):
            registration.post("/api/workers/heartbeat", registration.heartbeat_payload(8001, None))
        self.assertEqual(received[0][0], "/api/workers/heartbeat")
        self.assertEqual(received[0][1], "Bearer secret")
        self.assertEqual(received[0][2]["name"], "laptop")


if __name__ == "__main__":
    unittest.main()

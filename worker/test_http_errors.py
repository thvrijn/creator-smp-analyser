import http.client
import json
import os
import threading
import unittest
from http.server import ThreadingHTTPServer
from unittest.mock import patch

import entrypoint


class WorkerHttpErrorTest(unittest.TestCase):
    """Errors must always be full HTTP responses; a bare body is rejected by curl as HTTP/0.9."""

    def setUp(self) -> None:
        # The worker container has a WORKER_TOKEN; these tests send no token unless they test it.
        token = patch.dict(os.environ, {"WORKER_TOKEN": ""})
        token.start()
        self.addCleanup(token.stop)
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), entrypoint.WorkerRequestHandler)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)

    def post(self, path: str, body: str, headers: dict[str, str] | None = None) -> tuple[int, str, str]:
        connection = http.client.HTTPConnection("127.0.0.1", self.server.server_address[1], timeout=5)
        connection.request("POST", path, body=body, headers={"Content-Type": "application/json", **(headers or {})})
        response = connection.getresponse()
        result = response.status, response.getheader("Content-Type", ""), response.read().decode()
        connection.close()
        return result

    def test_invalid_model_output_returns_json_error(self) -> None:
        with patch.object(entrypoint._event_extractor, "extract", side_effect=ValueError("Het eventmodel gaf geen geldige JSON terug")):
            status, content_type, body = self.post("/extract-events", json.dumps({"segments": [{"text": "x"}]}))
        self.assertEqual(status, 422)
        self.assertEqual(content_type, "application/json")
        self.assertEqual(json.loads(body), {"error": "Het eventmodel gaf geen geldige JSON terug"})

    def test_unexpected_extraction_error_returns_500(self) -> None:
        with patch.object(entrypoint._event_extractor, "extract", side_effect=KeyError("boom")):
            status, _, body = self.post("/extract-events", json.dumps({"segments": []}))
        self.assertEqual(status, 500)
        self.assertIn("Workerfout", json.loads(body)["error"])

    def test_malformed_requests_return_400(self) -> None:
        for path, body in [("/extract-events", "not json"), ("/extract-events", json.dumps({"segments": "x"})), ("/transcribe", json.dumps({"stream_id": 1}))]:
            status, content_type, response = self.post(path, body)
            self.assertEqual(status, 400, path)
            self.assertEqual(content_type, "application/json")
            self.assertIn("error", json.loads(response))

    def test_transcription_failure_after_stream_start_is_an_ndjson_error_event(self) -> None:
        with patch.object(entrypoint, "transcribe_stream", side_effect=entrypoint.ProcessingError("streambestand bestaat niet: a.mp4")):
            status, content_type, body = self.post("/transcribe", json.dumps({"stream_id": 1, "video_path": "a.mp4"}))
        self.assertEqual(status, 200)
        self.assertEqual(content_type, "application/x-ndjson")
        self.assertEqual(json.loads(body), {"type": "error", "error": "streambestand bestaat niet: a.mp4"})

    def test_with_a_token_set_requests_without_it_are_refused(self) -> None:
        with patch.dict(os.environ, {"WORKER_TOKEN": "secret"}), \
                patch.object(entrypoint._event_extractor, "extract", return_value={"events": [], "summary": ""}):
            status, _, body = self.post("/extract-events", json.dumps({"segments": []}))
            self.assertEqual(status, 401)
            self.assertIn("token", json.loads(body)["error"])
            status, _, body = self.post("/extract-events", json.dumps({"segments": []}), {"Authorization": "Bearer secret"})
            self.assertEqual((status, json.loads(body)), (200, {"events": [], "summary": ""}))

    def test_extraction_gets_the_context_and_the_story_endpoint_answers_json(self) -> None:
        with patch.object(entrypoint._event_extractor, "extract", return_value={"events": [], "summary": "Niets."}) as extract:
            status, _, body = self.post("/extract-events", json.dumps({"segments": [{"text": "x"}], "context": {"streamer": "Morrog", "players": ["Morrog", "Jeremy"]}}))
        self.assertEqual((status, json.loads(body)), (200, {"events": [], "summary": "Niets."}))
        self.assertEqual(extract.call_args.args[1], {"streamer": "Morrog", "players": ["Morrog", "Jeremy"]})

        story = {"summary": "Morrog ontmoet Jeremy.", "players": ["Jeremy"]}
        with patch.object(entrypoint._event_extractor, "summarize_story", return_value=story):
            status, _, body = self.post("/summarize-story", json.dumps({"parts": [{"start_time": 0, "end_time": 300, "summary": "x"}], "events": []}))
        self.assertEqual((status, json.loads(body)), (200, story))
        for body in ["not json", json.dumps({"parts": "x", "events": []}), json.dumps({"parts": [], "events": [], "context": {"players": "x"}})]:
            status, _, _ = self.post("/summarize-story", body)
            self.assertEqual(status, 400, body)


if __name__ == "__main__":
    unittest.main()

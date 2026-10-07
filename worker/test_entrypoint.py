import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

import entrypoint
from model_manager import ModelManager


class FakeSegment:
    def __init__(self, start: float, end: float, text: str) -> None:
        self.start = start
        self.end = end
        self.text = text


class FakeModel:
    def transcribe(self, _audio_path: str, **_kwargs):
        return iter([
            FakeSegment(12.340, 16.870, " Waar is Lars? "),
            FakeSegment(18.0, 19.0, "   "),
        ]), object()


class WorkerTest(unittest.TestCase):
    def setUp(self) -> None:
        models = patch.object(entrypoint, "MODELS", ModelManager(release_memory=lambda: None))
        models.start()
        self.addCleanup(models.stop)

    def serve(self, body: bytes, status: int = 200) -> str:
        class App(BaseHTTPRequestHandler):
            def do_GET(self) -> None:  # noqa: N802
                self.send_response(status)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_args) -> None:
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), App)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return f"http://127.0.0.1:{server.server_address[1]}/api/worker-files/streams/12?signature=x"

    def test_a_worker_without_the_storage_downloads_the_audio_and_removes_it_afterwards(self) -> None:
        url = self.serve(b"audio" * 1000)
        events: list[dict] = []
        seen: list[bytes] = []

        def probe(path: Path) -> float:
            seen.append(path.read_bytes())
            return 100.0

        with tempfile.TemporaryDirectory() as directory, \
                patch.object(entrypoint, "STORAGE_ROOT", Path(directory).resolve()), \
                patch.object(entrypoint, "probe_duration", side_effect=probe) as probe_mock, \
                patch.object(entrypoint, "extract_audio"), \
                patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()):
            result = entrypoint.transcribe_stream(12, "streams/12/audio.m4a", events.append, None, url)
            downloaded = probe_mock.call_args.args[0]

        self.assertEqual(seen, [b"audio" * 1000])
        self.assertFalse(downloaded.exists())
        self.assertEqual(len(result["segments"]), 1)
        self.assertEqual(events[0], {"type": "stage", "stage": "downloading_audio", "progress": 0})
        self.assertIn({"type": "progress", "stage": "downloading_audio", "progress": 100}, events)

    def test_the_shared_storage_is_used_when_the_file_is_there(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            (root / "stream.mp4").write_bytes(b"video")
            with patch.object(entrypoint, "STORAGE_ROOT", root), patch.object(entrypoint, "download_stream_file") as download:
                path, downloaded = entrypoint.resolve_input(12, "stream.mp4", "http://app/file", lambda _event: None)
        self.assertEqual((path, downloaded), (root / "stream.mp4", False))
        download.assert_not_called()

    def test_a_failed_download_is_a_processing_error(self) -> None:
        url = self.serve(b"", status=403)
        with self.assertRaisesRegex(entrypoint.ProcessingError, "HTTP 403"):
            entrypoint.download_stream_file(12, url, lambda _event: None)

    def test_stream_file_must_stay_inside_storage_root(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()  # like STORAGE_ROOT; on macOS /var is a symlink
            (root / "streams" / "1" / "video").mkdir(parents=True)
            video = root / "streams" / "1" / "video" / "stream.mp4"
            video.write_bytes(b"video")

            with patch.object(entrypoint, "STORAGE_ROOT", root):
                self.assertEqual(entrypoint.resolve_stream_file("streams/1/video/stream.mp4"), video)
                with self.assertRaises(entrypoint.ProcessingError):
                    entrypoint.resolve_stream_file("../outside.mp4")

    def test_transcription_returns_real_segment_values_and_skips_empty_text(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()  # like STORAGE_ROOT; on macOS /var is a symlink
            video = root / "stream.mp4"
            video.write_bytes(b"video")

            with patch.object(entrypoint, "STORAGE_ROOT", root), \
                    patch.object(entrypoint, "probe_duration", return_value=100.0), \
                    patch.object(entrypoint, "extract_audio"), \
                    patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()):
                result = entrypoint.transcribe_stream(12, "stream.mp4")

        self.assertEqual(result["stream_id"], 12)
        self.assertEqual(result["segments"], [{"start": 12.34, "end": 16.87, "text": "Waar is Lars?"}])

    def test_progress_is_clamped_and_calculated_from_audio_time(self) -> None:
        self.assertEqual(entrypoint.progress_for(500, 1000), 50)
        self.assertEqual(entrypoint.progress_for(-5, 1000), 0)
        self.assertEqual(entrypoint.progress_for(1500, 1000), 100)
        self.assertEqual(entrypoint.progress_for(10, 0), 0)

    def test_transcription_emits_progress_and_completion_events(self) -> None:
        events = []
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()  # like STORAGE_ROOT; on macOS /var is a symlink
            video = root / "stream.mp4"
            video.write_bytes(b"video")
            with patch.object(entrypoint, "STORAGE_ROOT", root), \
                    patch.object(entrypoint, "probe_duration", return_value=1000.0), \
                    patch.object(entrypoint, "extract_audio"), \
                    patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()):
                entrypoint.transcribe_stream(12, "stream.mp4", events.append)

        self.assertEqual(events[0], {"type": "duration", "duration_seconds": 1000.0})
        self.assertEqual(events[1], {"type": "stage", "stage": "extracting_audio"})
        self.assertTrue(any(event.get("type") == "stage" and event.get("stage") == "transcribing" for event in events))
        self.assertIn({"type": "progress", "stage": "transcribing", "processed_seconds": 16.87, "progress": 2, "segment_count": 1}, events)
        self.assertEqual(events[-1]["type"], "completed")
        self.assertEqual(events[-1]["segment_count"], 4)

    def test_only_the_given_ranges_are_transcribed_with_file_times(self) -> None:
        events = []
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()  # like STORAGE_ROOT; on macOS /var is a symlink
            (root / "stream.mp4").write_bytes(b"video")
            with patch.object(entrypoint, "STORAGE_ROOT", root), \
                    patch.object(entrypoint, "probe_duration", return_value=1000.0), \
                    patch.object(entrypoint, "extract_audio") as extract_audio, \
                    patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()):
                result = entrypoint.transcribe_stream(12, "stream.mp4", events.append, [[500, 600], [100, 200], [150, 250]])

        # [100, 200] and [150, 250] overlap and merge; each part is extracted from its own start.
        self.assertEqual([call.args[3:] for call in extract_audio.call_args_list], [(150.0, 100.0), (100.0, 500.0)])
        self.assertEqual([segment["start"] for segment in result["segments"]], [112.34, 512.34])
        self.assertEqual(events[0], {"type": "duration", "duration_seconds": 250.0})
        self.assertIn({"type": "progress", "stage": "transcribing", "processed_seconds": 166.87, "progress": 67, "segment_count": 2}, events)

    def transcribe_with_diarization(self, diarize) -> tuple[dict, list[dict]]:
        events: list[dict] = []
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            (root / "stream.mp4").write_bytes(b"video")
            with patch.object(entrypoint, "STORAGE_ROOT", root), \
                    patch.object(entrypoint, "probe_duration", return_value=1000.0), \
                    patch.object(entrypoint, "extract_audio"), \
                    patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()), \
                    patch.object(entrypoint.diarization, "enabled", return_value=True), \
                    patch.object(entrypoint.diarization, "read_audio", return_value=None) as read_audio, \
                    patch.object(entrypoint.diarization, "load_pipeline", return_value=object()), \
                    patch.object(entrypoint.diarization, "diarize", side_effect=diarize):
                result = entrypoint.transcribe_stream(12, "stream.mp4", events.append, [[100, 500]])
                self.parts = read_audio.call_args.args[0]
        return result, events

    def test_diarization_gives_every_segment_a_speaker_in_the_kept_audio_time(self) -> None:
        def diarize(_pipeline, _waveform, on_progress):
            on_progress(0.5)
            # Audio time: the second chunk starts at 300 s there (file time 400 s).
            return [(0.0, 20.0, "STREAMER"), (310.0, 320.0, "SAM")], {"STREAMER": [1.0], "SAM": None}

        result, events = self.transcribe_with_diarization(diarize)

        self.assertEqual([(part[0].name, part[1]) for part in self.parts], [("audio-100.wav", 300.0), ("audio-400.wav", 100.0)])
        self.assertEqual(result["segments"], [
            {"start": 112.34, "end": 116.87, "text": "Waar is Lars?", "speaker": 0},
            {"start": 412.34, "end": 416.87, "text": "Waar is Lars?", "speaker": 1},
        ])
        self.assertIn({"type": "progress", "stage": "diarizing", "processed_seconds": 200.0, "progress": 50, "segment_count": 2}, events)
        self.assertEqual(events[-2], {"type": "speakers", "segment_speakers": [0, 1], "speakers": [
            {"speaker": 0, "seconds": 20.0, "embedding": [1.0]},
            {"speaker": 1, "seconds": 10.0, "embedding": None},
        ]})
        self.assertEqual(events[-1]["type"], "completed")

    def test_a_failed_diarization_keeps_the_transcript_without_speakers(self) -> None:
        def diarize(*_args):
            raise RuntimeError("CUDA out of memory")

        result, events = self.transcribe_with_diarization(diarize)

        self.assertEqual([segment.get("speaker") for segment in result["segments"]], [None, None])
        self.assertIn({"type": "warning", "message": "Sprekerherkenning mislukt: CUDA out of memory"}, events)
        self.assertEqual(events[-1]["type"], "completed")

    def test_a_closed_connection_stops_the_transcription(self) -> None:
        # The app closes the connection when the job is cancelled; the next event write raises ClientGone.
        sent: list[dict] = []

        def send(event: dict) -> None:
            if event["type"] == "segment":
                raise entrypoint.ClientGone()
            sent.append(event)

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            (root / "stream.mp4").write_bytes(b"video")
            with patch.object(entrypoint, "STORAGE_ROOT", root), \
                    patch.object(entrypoint, "probe_duration", return_value=100.0), \
                    patch.object(entrypoint, "extract_audio"), \
                    patch.object(entrypoint, "load_whisper_model", return_value=FakeModel()):
                with self.assertRaises(entrypoint.ClientGone):
                    entrypoint.transcribe_stream(12, "stream.mp4", send)

        self.assertNotIn("completed", [event["type"] for event in sent])

    def test_a_closed_connection_during_diarization_is_not_a_warning(self) -> None:
        def diarize(_pipeline, _waveform, _on_progress):
            raise entrypoint.ClientGone()

        with self.assertRaises(entrypoint.ClientGone):
            self.transcribe_with_diarization(diarize)

    def test_ranges_are_clipped_to_the_file(self) -> None:
        self.assertEqual(entrypoint.transcription_spans(None, 50.0), [(0.0, 50.0)])
        self.assertEqual(entrypoint.transcription_spans([[-5, 10], [40, 90], [60, 70]], 50.0), [(0.0, 10.0), (40.0, 50.0)])


if __name__ == "__main__":
    unittest.main()

import tempfile
import unittest
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

    def test_ranges_are_clipped_to_the_file(self) -> None:
        self.assertEqual(entrypoint.transcription_spans(None, 50.0), [(0.0, 50.0)])
        self.assertEqual(entrypoint.transcription_spans([[-5, 10], [40, 90], [60, 70]], 50.0), [(0.0, 10.0), (40.0, 50.0)])


if __name__ == "__main__":
    unittest.main()

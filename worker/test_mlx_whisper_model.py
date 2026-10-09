import sys
import types
import unittest
from unittest.mock import MagicMock, patch

import numpy as np

import entrypoint
import mlx_whisper_model
from mlx_whisper_model import MlxWhisperModel

RATE = mlx_whisper_model.SAMPLING_RATE


class MlxWhisperModelTest(unittest.TestCase):
    """mlx and mlx-whisper are replaced by fakes, so this also runs where they are not installed (Docker, CI)."""

    def setUp(self) -> None:
        self.holder = type("ModelHolder", (), {"model": None, "model_path": None, "get_model": MagicMock()})
        self.mlx_transcribe = MagicMock(return_value={"segments": []})
        core = types.ModuleType("mlx.core")
        core.float16 = "float16"
        mlx = types.ModuleType("mlx")
        mlx.core = core
        package = types.ModuleType("mlx_whisper")
        package.transcribe = self.mlx_transcribe
        submodule = types.ModuleType("mlx_whisper.transcribe")
        submodule.ModelHolder = self.holder
        modules = patch.dict(sys.modules, {"mlx": mlx, "mlx.core": core, "mlx_whisper": package, "mlx_whisper.transcribe": submodule})
        modules.start()
        self.addCleanup(modules.stop)
        audio = patch.object(mlx_whisper_model, "decode_audio", return_value=np.zeros(60 * RATE, dtype=np.float32))
        audio.start()
        self.addCleanup(audio.stop)

    def test_loading_warms_the_model_in_fp16(self) -> None:
        MlxWhisperModel("mlx-community/whisper-large-v3-turbo")

        self.holder.get_model.assert_called_once_with("mlx-community/whisper-large-v3-turbo", "float16")

    def test_vad_transcribes_only_the_speech_and_maps_the_times_back(self) -> None:
        # Speech at 10-20 s and 40-50 s: back to back that is 0-10 s and 10-20 s.
        speech = [{"start": 10 * RATE, "end": 20 * RATE}, {"start": 40 * RATE, "end": 50 * RATE}]
        self.mlx_transcribe.return_value = {"segments": [
            {"start": 2.0, "end": 5.5, "text": " Hoi"},
            {"start": 8.0, "end": 10.0, "text": " tot het eind"},
            {"start": 12.0, "end": 14.0, "text": " daar"},
        ]}

        with patch.object(mlx_whisper_model, "get_speech_timestamps", return_value=speech):
            segments, _info = MlxWhisperModel("repo").transcribe("chunk.wav", language="nl", vad_filter=True)

        self.assertEqual([(12.0, 15.5, " Hoi"), (18.0, 20.0, " tot het eind"), (42.0, 44.0, " daar")], [(s.start, s.end, s.text) for s in segments])
        audio = self.mlx_transcribe.call_args.args[0]
        self.assertEqual(20 * RATE, len(audio))
        self.assertEqual({"path_or_hf_repo": "repo", "language": "nl", "verbose": None}, self.mlx_transcribe.call_args.kwargs)

    def test_without_speech_nothing_is_transcribed(self) -> None:
        with patch.object(mlx_whisper_model, "get_speech_timestamps", return_value=[]):
            segments, _info = MlxWhisperModel("repo").transcribe("chunk.wav", language="nl", vad_filter=True)

        self.assertEqual([], segments)
        self.mlx_transcribe.assert_not_called()

    def test_without_vad_the_times_are_kept(self) -> None:
        self.mlx_transcribe.return_value = {"segments": [{"start": 3.0, "end": 4.0, "text": " Hoi"}]}

        with patch.object(mlx_whisper_model, "get_speech_timestamps") as vad:
            segments, _info = MlxWhisperModel("repo").transcribe("chunk.wav", language="nl")

        vad.assert_not_called()
        self.assertEqual([(3.0, 4.0)], [(s.start, s.end) for s in segments])
        self.assertEqual(60 * RATE, len(self.mlx_transcribe.call_args.args[0]))

    def test_a_repetition_loop_becomes_one_segment(self) -> None:
        loop = [{"start": 10.0 + i * 0.5, "end": 10.5 + i * 0.5, "text": " Oh."} for i in range(15)]
        self.mlx_transcribe.return_value = {"segments": [
            {"start": 1.0, "end": 1.5, "text": " Nee."},
            {"start": 1.5, "end": 2.0, "text": " nee."},
            {"start": 2.0, "end": 2.5, "text": " Nee."},
            *loop,
            {"start": 18.0, "end": 19.0, "text": " Copyrighted, ja."},
        ]}

        segments, _info = MlxWhisperModel("repo").transcribe("chunk.wav", language="nl")

        self.assertEqual(
            [(1.0, 1.5, " Nee."), (1.5, 2.0, " nee."), (2.0, 2.5, " Nee."), (10.0, 17.5, " Oh."), (18.0, 19.0, " Copyrighted, ja.")],
            [(s.start, s.end, s.text) for s in segments],
        )

    def test_unload_drops_the_cached_model(self) -> None:
        self.holder.model, self.holder.model_path = object(), "repo"

        entrypoint.unload_whisper_model(MlxWhisperModel("repo"))

        self.assertIsNone(self.holder.model)
        self.assertIsNone(self.holder.model_path)

    def test_the_device_picks_the_backend(self) -> None:
        with patch.object(entrypoint, "DEVICE", "mlx"), patch.object(entrypoint, "MODEL_NAME", "mlx-community/whisper-large-v3-turbo"):
            self.assertIsInstance(entrypoint.load_whisper_model(), MlxWhisperModel)
        with patch.object(entrypoint, "DEVICE", "cpu"), patch.object(entrypoint, "WhisperModel") as whisper:
            entrypoint.load_whisper_model()
        whisper.assert_called_once()


if __name__ == "__main__":
    unittest.main()

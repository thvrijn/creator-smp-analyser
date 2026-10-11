"""Whisper on Apple's GPU (Metal) through mlx-whisper, for the native Mac worker (WHISPER_DEVICE=mlx).

MlxWhisperModel offers the part of faster-whisper's WhisperModel that entrypoint.py uses, transcribe(), so the
chunks, progress, cancelling and diarization work the same on both. mlx is only imported when a model is loaded,
so this module also imports where mlx is not installed (Docker, CI).
"""
from types import SimpleNamespace
from typing import Any

import numpy as np
from faster_whisper import decode_audio
from faster_whisper.vad import SpeechTimestampsMap, VadOptions, collect_chunks, get_speech_timestamps

SAMPLING_RATE = 16000
# More identical segments in a row than this is a greedy-decoding loop (measured: 15x "Oh." in 7 s of music), not speech.
MAX_REPEATS = 3


class MlxWhisperModel:
    def __init__(self, repo: str) -> None:
        import mlx.core as mx
        from mlx_whisper.transcribe import ModelHolder

        self.repo = repo
        # mlx-whisper keeps its model in this class-level cache; transcribe() below finds it there (fp16, its default).
        ModelHolder.get_model(repo, mx.float16)

    def transcribe(
        self, audio_path: str, language: str | None = None, vad_filter: bool = False, word_timestamps: bool = False,
        initial_prompt: str | None = None, **_kwargs: Any,
    ) -> tuple[list[Any], None]:
        import mlx_whisper

        audio = decode_audio(audio_path, sampling_rate=SAMPLING_RATE)
        timestamps = None
        if vad_filter:
            # What faster-whisper's vad_filter does: transcribe only the speech, back to back, and map the times back.
            speech = get_speech_timestamps(audio, VadOptions())
            if not speech:
                return [], None
            parts, _metadata = collect_chunks(audio, speech)
            audio = np.concatenate(parts)
            timestamps = SpeechTimestampsMap(speech, SAMPLING_RATE)
        # Greedy decoding: mlx-whisper has no beam search. verbose=None prints nothing.
        options: dict[str, Any] = {"word_timestamps": word_timestamps}
        if initial_prompt:
            options["initial_prompt"] = initial_prompt
        result = mlx_whisper.transcribe(audio, path_or_hf_repo=self.repo, language=language, verbose=None, **options)

        def original(start: float, end: float) -> tuple[float, float]:
            if timestamps is None:
                return start, end
            return timestamps.get_original_time(start), timestamps.get_original_time(end, is_end=True)

        segments = []
        for segment in result["segments"]:
            start, end = original(float(segment["start"]), float(segment["end"]))
            words = [SimpleNamespace(start=word_start, end=word_end, word=word["word"])
                     for word in segment.get("words") or []
                     for word_start, word_end in [original(float(word["start"]), float(word["end"]))]]
            segments.append(SimpleNamespace(
                start=start, end=end, text=segment["text"], words=words or None,
                no_speech_prob=float(segment.get("no_speech_prob", 0.0)), avg_logprob=float(segment.get("avg_logprob", 0.0)),
            ))
        return collapse_repeats(segments), None

    def unload(self) -> None:
        from mlx_whisper.transcribe import ModelHolder

        # Drops the last reference; ModelManager then frees the Metal memory (gc + mx.clear_cache).
        ModelHolder.model = None
        ModelHolder.model_path = None


def collapse_repeats(segments: list[Any]) -> list[Any]:
    """Turns a run of more than MAX_REPEATS identical segments into one segment over the run's time.

    mlx-whisper only decodes greedily (faster-whisper uses beam search), which can loop over music or noise.
    Turning condition_on_previous_text off also stops that, but made the whole transcript less accurate.
    """
    collapsed: list[Any] = []
    run: list[Any] = []
    for segment in [*segments, None]:
        if segment is not None and run and segment.text.strip().lower() == run[0].text.strip().lower():
            run.append(segment)
            continue
        if len(run) > MAX_REPEATS:
            collapsed.append(SimpleNamespace(start=run[0].start, end=run[-1].end, text=run[0].text, words=None))
        else:
            collapsed.extend(run)
        run = [segment] if segment is not None else []
    return collapsed

"""Speaker diarization with pyannote: who speaks when, as anonymous speakers (no names).

Runs after Whisper over the same audio. Speakers are numbered by speaking time, so speaker 0
speaks the most; in a player's own stream that is almost always the streamer. Each speaker's
voice embedding is returned too, for matching voices across streams later.
"""
import math
import os
import wave
from pathlib import Path
from typing import Any, Callable

import numpy as np

# Gated on Hugging Face: accept the conditions of this pipeline and pyannote/segmentation-3.0 with the account of HF_TOKEN.
MODEL = os.getenv("DIARIZATION_MODEL", "pyannote/speaker-diarization-3.1")
SAMPLE_RATE = 16000
# pyannote clusters every voice embedding at once by default (memory grows with the square of their number:
# a 5 h stream has ~25k, gigabytes). It clusters a sample of this many; the rest is assigned to the clusters found.
MAX_CLUSTER_EMBEDDINGS = int(os.getenv("DIARIZATION_MAX_CLUSTER_EMBEDDINGS", "10000"))
# Rough share of the run per pyannote step, for the progress bar; the embeddings take the longest.
STEP_WEIGHTS = {"segmentation": (0.0, 0.3), "embeddings": (0.3, 0.95)}


def enabled() -> bool:
    """Off without a model or a Hugging Face token: the transcript then has no speakers."""
    return bool(MODEL) and bool(os.getenv("HF_TOKEN"))


def device() -> str:
    import torch

    return os.getenv("DIARIZATION_DEVICE") or ("cuda" if torch.cuda.is_available() else "cpu")


def allow_checkpoint_classes() -> None:
    """torch>=2.6 only unpickles allowlisted classes (weights_only); pyannote 3.4's checkpoints also hold these."""
    import torch
    from pyannote.audio.core.task import Problem, Resolution, Specifications

    torch.serialization.add_safe_globals([torch.torch_version.TorchVersion, Specifications, Problem, Resolution])


def open_pipeline() -> Any:
    """The pipeline on the CPU; downloads it into HF_HOME the first time. huggingface_hub reads HF_TOKEN from the environment."""
    from pyannote.audio import Pipeline

    allow_checkpoint_classes()
    pipeline = Pipeline.from_pretrained(MODEL)
    if pipeline is None:
        raise RuntimeError(f"{MODEL} kon niet worden geladen: accepteer de voorwaarden op Hugging Face en controleer HF_TOKEN")
    return pipeline


def load_pipeline() -> Any:
    import torch

    pipeline = open_pipeline()
    pipeline.clustering.max_num_embeddings = MAX_CLUSTER_EMBEDDINGS
    # The code default is 1 per batch; the GPU does many at once.
    pipeline.embedding_batch_size = max(32, pipeline.embedding_batch_size)
    return pipeline.to(torch.device(device()))


def unload_pipeline(pipeline: Any) -> None:
    import torch

    pipeline.to(torch.device("cpu"))


def read_audio(parts: list[tuple[Path, float]]) -> np.ndarray:
    """The 16 kHz mono WAV parts back to back, each padded or cut to its nominal duration so times line up."""
    waveforms = []
    for path, duration in parts:
        with wave.open(str(path), "rb") as file:
            samples = np.frombuffer(file.readframes(file.getnframes()), dtype=np.int16)
        expected = round(duration * SAMPLE_RATE)
        samples = np.pad(samples, (0, max(0, expected - len(samples))))[:expected]
        waveforms.append(samples.astype(np.float32) / 32768.0)
    return np.concatenate(waveforms) if waveforms else np.zeros(0, dtype=np.float32)


def progress_hook(on_progress: Callable[[float], None]) -> Callable[..., None]:
    """A pyannote hook that reports the run's progress as a fraction (0-1)."""
    def hook(step_name: str, _artifact: Any, file: Any = None, total: int | None = None, completed: int | None = None) -> None:
        if step_name in STEP_WEIGHTS and total and completed is not None:
            start, end = STEP_WEIGHTS[step_name]
            on_progress(start + (end - start) * min(1.0, completed / total))

    return hook


def diarize(pipeline: Any, waveform: np.ndarray, on_progress: Callable[[float], None]) -> tuple[list[tuple[float, float, str]], dict[str, list[float] | None]]:
    """Runs the pipeline: speech turns (start, end, label) in waveform time, and each label's voice embedding."""
    import torch

    audio = {"waveform": torch.from_numpy(waveform).unsqueeze(0), "sample_rate": SAMPLE_RATE}
    annotation, embeddings = pipeline(audio, hook=progress_hook(on_progress), return_embeddings=True)
    turns = [(float(turn.start), float(turn.end), str(label)) for turn, _track, label in annotation.itertracks(yield_label=True)]
    voices: dict[str, list[float] | None] = {}
    for index, label in enumerate(annotation.labels()):
        vector = [float(value) for value in embeddings[index]] if embeddings is not None and index < len(embeddings) else []
        # A speaker with too little speech gets no embedding (zeros) or a NaN one.
        voices[str(label)] = vector if any(vector) and all(math.isfinite(value) for value in vector) else None
    return turns, voices


def assign_speakers(
    segment_times: list[tuple[float, float]],
    turns: list[tuple[float, float, str]],
    voices: dict[str, list[float] | None],
) -> tuple[list[int | None], list[dict[str, Any]]]:
    """Gives each segment the speaker that speaks most during it (None without any overlap).

    Speakers are numbered by total speaking time, most first. Returns the segment speakers and
    per speaker its number, speaking time and voice embedding.
    """
    seconds: dict[str, float] = {}
    for start, end, label in turns:
        seconds[label] = seconds.get(label, 0.0) + max(0.0, end - start)
    order = sorted(seconds, key=lambda label: (-seconds[label], label))
    numbers = {label: number for number, label in enumerate(order)}

    ordered_turns = sorted(turns)
    speakers: list[int | None] = []
    for segment_start, segment_end in segment_times:
        overlap: dict[str, float] = {}
        for start, end, label in ordered_turns:
            if start >= segment_end:
                break
            shared = min(end, segment_end) - max(start, segment_start)
            if shared > 0:
                overlap[label] = overlap.get(label, 0.0) + shared
        speakers.append(numbers[max(overlap, key=lambda label: (overlap[label], -numbers[label]))] if overlap else None)

    summary = [
        {"speaker": numbers[label], "seconds": round(seconds[label], 3), "embedding": voices.get(label)}
        for label in order
    ]
    return speakers, summary

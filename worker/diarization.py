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
# A speaker change inside a Whisper segment splits it only when both sides have at least this many words and seconds;
# shorter runs are diarization jitter around a word ("ja", a laugh) and stay with their neighbours.
MIN_SPLIT_WORDS = int(os.getenv("DIARIZATION_MIN_SPLIT_WORDS", "3"))
MIN_SPLIT_SECONDS = float(os.getenv("DIARIZATION_MIN_SPLIT_SECONDS", "1.0"))
# Rough share of the run per pyannote step, for the progress bar; the embeddings take the longest.
STEP_WEIGHTS = {"segmentation": (0.0, 0.3), "embeddings": (0.3, 0.95)}


def enabled() -> bool:
    """Off without a model or a Hugging Face token: the transcript then has no speakers."""
    return bool(MODEL) and bool(os.getenv("HF_TOKEN"))


def device() -> str:
    """CUDA, else the Mac's GPU: on the M4 Pro MPS diarized 5 min in 17 s against 154 s on the CPU, with the same speakers and embeddings."""
    import torch

    if os.getenv("DIARIZATION_DEVICE"):
        return os.environ["DIARIZATION_DEVICE"]
    if torch.cuda.is_available():
        return "cuda"
    return "mps" if torch.backends.mps.is_available() else "cpu"


def allow_checkpoint_classes() -> None:
    """torch>=2.6 only unpickles allowlisted classes (weights_only); pyannote 3.4's checkpoints also hold these."""
    import torch
    from pyannote.audio.core.task import Problem, Resolution, Specifications

    torch.serialization.add_safe_globals([torch.torch_version.TorchVersion, Specifications, Problem, Resolution])


def accept_use_auth_token() -> None:
    """pyannote 3.4 downloads with hf_hub_download(use_auth_token=...), which huggingface_hub>=1.0 no longer accepts.

    The Mac needs that huggingface_hub (mlx-lm needs transformers 5), so the argument is passed on as `token`.
    """
    import huggingface_hub
    from pyannote.audio.core import model, pipeline
    from pyannote.audio.pipelines import speaker_verification

    def hf_hub_download(*args: Any, **kwargs: Any) -> str:
        token = kwargs.pop("use_auth_token", None)
        if token is not None:
            kwargs.setdefault("token", token)
        return huggingface_hub.hf_hub_download(*args, **kwargs)

    for module in (model, pipeline, speaker_verification):
        module.hf_hub_download = hf_hub_download


def open_pipeline() -> Any:
    """The pipeline on the CPU; downloads it into HF_HOME the first time. huggingface_hub reads HF_TOKEN from the environment."""
    from pyannote.audio import Pipeline

    allow_checkpoint_classes()
    accept_use_auth_token()
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


def speaker_numbers(turns: list[tuple[float, float, str]]) -> dict[str, int]:
    """Pyannote's labels numbered by total speaking time, most first (0 = the streamer, almost always)."""
    seconds: dict[str, float] = {}
    for start, end, label in turns:
        seconds[label] = seconds.get(label, 0.0) + max(0.0, end - start)
    return {label: number for number, label in enumerate(sorted(seconds, key=lambda label: (-seconds[label], label)))}


def _label_at(start: float, end: float, turns: list[tuple[float, float, str]]) -> str | None:
    """The label speaking most during [start, end] (turns sorted by start)."""
    overlap: dict[str, float] = {}
    for turn_start, turn_end, label in turns:
        if turn_start >= end:
            break
        shared = min(turn_end, end) - max(turn_start, start)
        if shared > 0:
            overlap[label] = overlap.get(label, 0.0) + shared
    return max(overlap, key=lambda label: (overlap[label], label)) if overlap else None


def split_by_speaker(segments: list[dict[str, Any]], turns: list[tuple[float, float, str]]) -> dict[int, list[dict[str, Any]]]:
    """Splits Whisper segments in which the speaker changes, at the word where it changes.

    A segment needs `_words` ([(audio start, audio end, word)]), `_audio_start` and file times `start`/`end`. Returns,
    by segment index, the parts that replace it: {start, end, text, speaker} in file time. Words without a speaker
    join the run before them; runs shorter than MIN_SPLIT_WORDS/MIN_SPLIT_SECONDS join a neighbour.
    """
    numbers = speaker_numbers(turns)
    ordered_turns = sorted(turns)
    splits: dict[int, list[dict[str, Any]]] = {}
    for index, segment in enumerate(segments):
        words = segment.get("_words") or []
        if len(words) < 2 * MIN_SPLIT_WORDS:
            continue
        runs: list[dict[str, Any]] = []
        for start, end, word in words:
            label = _label_at(start, end, ordered_turns) or (runs[-1]["label"] if runs else None)
            if runs and runs[-1]["label"] == label:
                runs[-1]["words"].append((start, end, word))
            else:
                runs.append({"label": label, "words": [(start, end, word)]})
        runs = _snap_to_punctuation(_merge_short_runs(runs))
        if len(runs) < 2:
            continue
        offset = float(segment["start"]) - float(segment["_audio_start"])  # audio time -> file time
        splits[index] = [{
            "start": round(run["words"][0][0] + offset, 3),
            "end": round(run["words"][-1][1] + offset, 3),
            "text": "".join(word for _start, _end, word in run["words"]).strip(),
            "speaker": numbers.get(run["label"]) if run["label"] is not None else None,
        } for run in runs]
    return splits


# A speaker change is moved to a punctuation mark at most this many words away: diarization's boundaries are a few
# tenths of a second off, and people mostly hand over at the end of a sentence or clause.
SNAP_WORDS = 2
PUNCTUATION = (".", ",", "?", "!", "…", ";", ":")


def _snap_to_punctuation(runs: list[dict[str, Any]]) -> list[dict[str, Any]]:
    for left, right in zip(runs, runs[1:]):
        words = left["words"] + right["words"]
        cut = len(left["words"])  # the first word of the right run
        if words[cut - 1][2].strip().endswith(PUNCTUATION):
            continue
        # The nearest word ending in punctuation, at most SNAP_WORDS away, that leaves both runs a word.
        for distance in range(1, SNAP_WORDS + 1):
            candidates = [c for c in (cut + distance, cut - distance) if 1 <= c <= len(words) - 1 and words[c - 1][2].strip().endswith(PUNCTUATION)]
            if candidates:
                cut = candidates[0]
                left["words"], right["words"] = words[:cut], words[cut:]
                break
    return runs


def _merge_short_runs(runs: list[dict[str, Any]]) -> list[dict[str, Any]]:
    def short(run: dict[str, Any]) -> bool:
        return len(run["words"]) < MIN_SPLIT_WORDS or run["words"][-1][1] - run["words"][0][0] < MIN_SPLIT_SECONDS

    # The shortest run first, into the longer of its neighbours, until every run is long enough.
    while len(runs) > 1 and any(short(run) for run in runs):
        index = min((i for i, run in enumerate(runs) if short(run)), key=lambda i: len(runs[i]["words"]))
        before = runs[index - 1] if index > 0 else None
        after = runs[index + 1] if index + 1 < len(runs) else None
        target = before if after is None or (before is not None and len(before["words"]) >= len(after["words"])) else after
        if target is before:
            before["words"].extend(runs[index]["words"])
        else:
            after["words"][:0] = runs[index]["words"]
        del runs[index]
        # Neighbours with the same speaker are one run again.
        merged: list[dict[str, Any]] = []
        for run in runs:
            if merged and merged[-1]["label"] == run["label"]:
                merged[-1]["words"].extend(run["words"])
            else:
                merged.append(run)
        runs = merged
    return runs


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
    numbers = speaker_numbers(turns)
    order = sorted(numbers, key=numbers.get)

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

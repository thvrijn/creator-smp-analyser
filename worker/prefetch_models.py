"""Download the Whisper, event and diarization models into the persistent caches.

Run by `make start`, so the first transcription or extraction job does not have to wait
for a multi-GB download. Files that are already cached are not downloaded again.
"""
import os

from faster_whisper.utils import download_model
from huggingface_hub import snapshot_download

import diarization


def main() -> None:
    whisper_model = os.getenv("WHISPER_MODEL", "large-v3-turbo")
    whisper_cache = os.getenv("WHISPER_MODEL_CACHE", "/worker/.cache")
    event_model = os.getenv("EVENT_MODEL", "unsloth/Qwen3-8B-bnb-4bit")

    if os.getenv("WHISPER_DEVICE") == "mlx":
        # An MLX checkpoint (scripts/worker-mac.sh), loaded by mlx-whisper from the Hugging Face cache.
        print(f"prefetch: whisper {whisper_model} -> {os.getenv('HF_HOME', '~/.cache/huggingface')}", flush=True)
        snapshot_download(whisper_model)
    else:
        print(f"prefetch: whisper {whisper_model} -> {whisper_cache}", flush=True)
        download_model(whisper_model, cache_dir=whisper_cache)

    print(f"prefetch: event model {event_model} -> {os.getenv('HF_HOME', '~/.cache/huggingface')}", flush=True)
    snapshot_download(event_model, allow_patterns=["*.json", "*.safetensors", "*.txt", "*.model", "*.jinja"])

    if diarization.enabled():
        # Loading on the CPU downloads the pipeline and the models it uses (small, a few seconds).
        print(f"prefetch: diarization {diarization.MODEL}", flush=True)
        try:
            diarization.open_pipeline()
        except Exception as exc:
            # Not fatal: transcription works without speakers.
            print(
                f"prefetch: WARNING diarization model unavailable ({exc}). Accept the conditions of "
                f"https://hf.co/{diarization.MODEL} and https://hf.co/pyannote/segmentation-3.0 with the HF_TOKEN account.",
                flush=True,
            )
    else:
        print("prefetch: diarization off (no HF_TOKEN)", flush=True)
    print("prefetch: done", flush=True)


if __name__ == "__main__":
    main()

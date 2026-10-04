"""Download the Whisper and event models into the persistent caches without loading them.

Run by `make start`, so the first transcription or extraction job does not have to wait
for a multi-GB download. Files that are already cached are not downloaded again.
"""
import os

from faster_whisper.utils import download_model
from huggingface_hub import snapshot_download


def main() -> None:
    whisper_model = os.getenv("WHISPER_MODEL", "small")
    whisper_cache = os.getenv("WHISPER_MODEL_CACHE", "/worker/.cache")
    event_model = os.getenv("EVENT_MODEL", "unsloth/Qwen3-8B-bnb-4bit")

    print(f"prefetch: whisper {whisper_model} -> {whisper_cache}", flush=True)
    download_model(whisper_model, cache_dir=whisper_cache)

    print(f"prefetch: event model {event_model} -> {os.getenv('HF_HOME', '~/.cache/huggingface')}", flush=True)
    snapshot_download(event_model, allow_patterns=["*.json", "*.safetensors", "*.txt", "*.model", "*.jinja"])
    print("prefetch: done", flush=True)


if __name__ == "__main__":
    main()

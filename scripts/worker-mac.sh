#!/usr/bin/env sh
# Runs the worker's Python natively on macOS, where Docker cannot reach the GPU (used by the Makefile).
# Qwen runs on Metal through MLX; Whisper runs on the CPU (faster-whisper has no Metal backend).
root="$(cd "$(dirname "$0")/.." && pwd)"
export WORKER_HOST=127.0.0.1
export WORKER_STORAGE_ROOT="$root/storage/app/private"
export WHISPER_DEVICE=cpu WHISPER_COMPUTE_TYPE=int8 WHISPER_MODEL_CACHE="$root/worker/.cache"
export EVENT_DEVICE=mlx EVENT_MODEL=mlx-community/Qwen3-8B-4bit HF_HOME="$root/worker/model-cache"
exec "$root/worker/.venv/bin/python" "$@"

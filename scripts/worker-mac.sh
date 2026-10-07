#!/usr/bin/env sh
# Runs the worker's Python natively on macOS, where Docker cannot reach the GPU (used by the Makefile).
# Qwen runs on Metal through MLX; Whisper runs on the CPU (faster-whisper has no Metal backend).
root="$(cd "$(dirname "$0")/.." && pwd)"
# A remote worker's settings (make remote-worker). Without them it checks in with the local dev stack.
if [ -f "$root/worker/.env" ]; then set -a; . "$root/worker/.env"; set +a; fi
WORKER_TOKEN="${WORKER_TOKEN:-$(sed -n 's/^WORKER_TOKEN=//p' "$root/.env" 2>/dev/null)}"
export WORKER_TOKEN
HF_TOKEN="${HF_TOKEN:-$(sed -n 's/^HF_TOKEN=//p' "$root/.env" 2>/dev/null)}"
export HF_TOKEN
export WORKER_HOST="${WORKER_HOST:-127.0.0.1}"
export WORKER_NAME="${WORKER_NAME:-mac-local}"
export WORKER_APP_URL="${WORKER_APP_URL:-http://localhost:8000}"
export WORKER_PUBLIC_URL="${WORKER_PUBLIC_URL:-http://host.docker.internal:8001}"
export WORKER_STORAGE_ROOT="$root/storage/app/private"
export WHISPER_DEVICE="${WHISPER_DEVICE:-cpu}" WHISPER_COMPUTE_TYPE="${WHISPER_COMPUTE_TYPE:-int8}" WHISPER_MODEL_CACHE="$root/worker/.cache"
export EVENT_DEVICE="${EVENT_DEVICE:-mlx}" EVENT_MODEL="${EVENT_MODEL:-mlx-community/Qwen3-8B-4bit}" HF_HOME="$root/worker/model-cache"
exec "$root/worker/.venv/bin/python" "$@"

#!/usr/bin/env sh
# Runs the worker's Python natively on macOS, where Docker cannot reach the GPU (used by the Makefile).
# Qwen and Whisper run on Metal through MLX (mlx-lm, mlx-whisper); WHISPER_DEVICE=cpu puts Whisper back on faster-whisper.
root="$(cd "$(dirname "$0")/.." && pwd)"
# A remote worker's settings (make remote-worker). Without them it checks in with the local dev stack.
# Read as KEY=value lines like Docker's env_file, not sourced: a value with a space ("Macbook Pro") would
# run as a command and leave the setting unset. One pair of surrounding quotes is stripped.
remote=
if [ -f "$root/worker/.env" ]; then
    remote=1
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}" value="${line#*=}"
        case "$key" in ''|[!A-Za-z_]*|*[!A-Za-z0-9_]*) continue ;; esac
        case "$value" in \"*\"|\'*\') value="${value#?}" value="${value%?}" ;; esac
        export "$key=$value"
    done < "$root/worker/.env"
fi
WORKER_TOKEN="${WORKER_TOKEN:-$(sed -n 's/^WORKER_TOKEN=//p' "$root/.env" 2>/dev/null)}"
export WORKER_TOKEN
HF_TOKEN="${HF_TOKEN:-$(sed -n 's/^HF_TOKEN=//p' "$root/.env" 2>/dev/null)}"
export HF_TOKEN
# A remote worker is reached from another machine (WORKER_PUBLIC_URL), the local one only from Docker on this Mac.
if [ -n "$remote" ]; then export WORKER_HOST="${WORKER_HOST:-0.0.0.0}"; else export WORKER_HOST="${WORKER_HOST:-127.0.0.1}"; fi
export WORKER_NAME="${WORKER_NAME:-mac-local}"
export WORKER_APP_URL="${WORKER_APP_URL:-http://localhost:8000}"
export WORKER_PUBLIC_URL="${WORKER_PUBLIC_URL:-http://host.docker.internal:8001}"
export WORKER_STORAGE_ROOT="$root/storage/app/private"
export WHISPER_DEVICE="${WHISPER_DEVICE:-mlx}" WHISPER_COMPUTE_TYPE="${WHISPER_COMPUTE_TYPE:-int8}" WHISPER_MODEL_CACHE="$root/worker/.cache"
# On MLX the model is a Hugging Face checkpoint (in HF_HOME), on the CPU a faster-whisper model name (in WHISPER_MODEL_CACHE).
if [ "$WHISPER_DEVICE" = mlx ]; then export WHISPER_MODEL="${WHISPER_MODEL:-mlx-community/whisper-large-v3-turbo}"; else export WHISPER_MODEL="${WHISPER_MODEL:-large-v3-turbo}"; fi
export EVENT_DEVICE="${EVENT_DEVICE:-mlx}" EVENT_MODEL="${EVENT_MODEL:-mlx-community/Qwen3-8B-4bit}" HF_HOME="$root/worker/model-cache"
exec "$root/worker/.venv/bin/python" "$@"

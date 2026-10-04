# AGENTS.md

This file provides guidance to AI coding agents (Codex, Claude Code, etc.) when working with code in this repository.

## Project

CreatorSMP4 turns Minecraft livestream recordings (Dutch-language, "Creator SMP 4") into recaps: upload a stream video → transcribe it with faster-whisper → extract notable events with a local LLM (Qwen3-8B) → (planned) timeline and video builder. The README and some UI/error strings are in Dutch.

## Data safety (from README — mandatory)

Never wipe or reset the database or volumes without explicit permission: no `migrate:fresh`, `db:wipe`, `docker compose down -v` or similar. Existing players, streams, transcripts and uploads must be kept. Add new migrations instead of editing old ones, and check existing data before deleting records, tables or volumes.

**Tests use a separate database:** the feature tests use `RefreshDatabase`, which wipes the database it runs against. `phpunit.xml` points them at `creatorsmp4_test` (in the same Postgres container) with both `<server>` and `<env>` set with `force="true"`. Both are needed: Docker injects `DB_*` as real environment variables, and Laravel reads `$_SERVER` first, so `<env>` alone silently falls back to the dev database. Don't remove these overrides. If the Postgres volume is ever recreated, recreate the test database with `docker compose exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -c "CREATE DATABASE creatorsmp4_test OWNER creatorsmp4"`.

## Commands

Everything runs in Docker Compose. Use the Makefile (`make help`):

- `make start`: the one command to get a working stack. It runs `up -d --build --wait` (layer cache, so it only rebuilds what changed), creates `creatorsmp4_test` if missing (`make test-db`), downloads the Whisper and event models into their caches (`make models`), and runs `scripts/check.sh` (`make check`: postgres, test DB, redis, migrations, app, queue, worker `/health`, GPU/CUDA). Keep this working whenever setup steps are added. `make stop` brings the stack down. The `app` container runs `migrate --force`, `artisan serve` (:8000) and Vite dev (:5173, HMR) together.
- `make test`: Laravel feature tests (`php artisan test` in `app`). Run one test with `make test-filter FILTER='--filter=TranscriptionJobTest'` (or `--filter=test_method_name`).
- `make worker-test`: all Python worker tests (`unittest discover` over `worker/test_*.py`). Run one file with `docker compose exec -T worker python3 -m unittest /worker/test_model_manager.py`.
- `make artisan CMD='...'`, `make migrate`, `make build` (Vite production build), `make shell`.
- `make logs-app` / `logs-queue` / `logs-worker`.
- `php artisan stream:transcribe {id} [--sync]`: queue a transcription (or run it inline to debug) for an existing stream.
- Pint (`vendor/bin/pint`) is installed as a dev dependency. There is no JS lint or typecheck script.

## Architecture

Docker services: `app` (PHP 8.3 + Node: Laravel 12 + Inertia/Vue 3/TS), `queue` (`queue:work redis`, timeout 3600s), `postgres`, `redis` (cache + queue), and `worker` (a Python HTTP server on :8001 that needs an NVIDIA GPU through the Container Toolkit; for CPU, set `WHISPER_DEVICE=cpu`, `WHISPER_COMPUTE_TYPE=int8`, `EVENT_DEVICE=cpu`).

**Processing pipeline (spans PHP + Python):**

1. `StreamController@store` saves the uploaded video to the default disk (`storage/app/private`, max size from `config/streams.php`).
2. `POST /streams/{id}/transcribe` dispatches `App\Jobs\TranscribeStreamJob`. `App\Services\TranscriptionWorker` POSTs `{stream_id, video_path}` to `worker /transcribe` and reads back a **streaming NDJSON** response. Event types: `duration`, `stage`, `progress`, `segment`, `completed`, `error`. The job throttles progress writes into the `transcription_*` telemetry columns on `streams` (stage, progress, processed/duration seconds, ETA), then replaces all `transcript_segments` in one transaction.
3. `POST /streams/{id}/extract-events` dispatches `ExtractStreamEventsJob`. It splits the segments into time windows that overlap (`EVENT_CHUNK_SECONDS`/`EVENT_CHUNK_OVERLAP_SECONDS`, see `config/services.php`), POSTs each chunk to `worker /extract-events` (plain JSON), validates again, dedupes by type+title+segment IDs, and stores `events` linked to segments through the `event_transcript_segment` pivot.
   - Validation rules (agreed with the user), applied on both sides: an invalid event is skipped and logged, never stored, and the rest of the chunk is kept. Event `start_time`/`end_time` are always derived from the referenced segments, never taken from the model (the prompt no longer asks for timestamps).
   - A chunk whose model output is unusable (worker HTTP 422 → `InvalidModelOutputException`) is skipped, and the job completes with a note in `event_extraction_error`. An unreachable or crashing worker (other errors) fails the job.
   - Events are collected in memory and replace the stream's previous events in one transaction at the end, so re-runs and queue retries never duplicate, and a failed run keeps the previous result.

Key cross-language contracts:
- The worker reads videos from the **same storage directory**, mounted read-only (`WORKER_STORAGE_ROOT`). `video_path` must be a relative path inside that root; `resolve_stream_file` rejects path traversal.
- Event types are defined twice and must stay in sync: `EVENT_TYPES` in `worker/event_extractor.py` and `$types` in `ExtractStreamEventsJob::validateEvent`. Both sides check that segment indexes are relative to the chunk and that timestamps fall inside the chunk.
- Both jobs use `lockForUpdate` + a `*_status === 'processing'` guard so they are idempotent, and they set the status to `failed` with the error message in their `catch`/`failed()`. `markAsProcessing` saves through a locked copy, so `handle()` must `$stream->refresh()` afterwards. Otherwise a later `completed`/`failed` save is skipped as "unchanged" and the stream stays stuck on `processing`.
- Worker HTTP contract: `/extract-events` always answers with a complete JSON response (400 bad request, 422 unusable model output, 500 crash). `/transcribe` validates the request before streaming NDJSON, and errors after that are `{"type":"error"}` lines. Never write a body without a status line: curl rejects it as "HTTP/0.9".
- `queue:work` and the Python worker keep code in memory. After changing jobs/services or `worker/*.py`, run `docker compose restart queue worker`.
- **GPU model swapping** (`worker/model_manager.py`): the GPU has 8 GB of VRAM and cannot hold Whisper (~0.6 GB) and Qwen (~6.1 GB, `unsloth/Qwen3-8B-bnb-4bit`, pre-quantized) together. `ModelManager` keeps one model loaded and swaps when the other is requested. The model stays loaded between requests (so the chunk requests of one extraction do not reload it) and is unloaded after `WORKER_MODEL_IDLE_SECONDS` (default 600). A lock is held while a model is in use: transcription holds Whisper for the whole stream, and an event request waits. Measured: Whisper loads in ~1-2 s, Qwen in ~11-18 s, inference takes ~5-8 s per chunk. Never keep references to a model outside `MODELS.use(...)`, or the VRAM is not freed.
- Model caches persist in `worker/.cache` (Whisper, bind mount) and the `event_model_cache` volume (`HF_HOME`). `worker/.dockerignore` keeps them out of the image. Changing `worker/requirements.txt` triggers a full rebuild of the ~7 GB worker image (pip + layer export, 10+ minutes).

**Frontend:** Inertia pages are in `resources/js/Pages/**` (resolved by name in `app.ts`), and controllers return `Inertia::render(...)` with props mapped explicitly. `/dashboard` shows a card per player (`PlayerController@dashboard`, stats from `Player::scopeWithStreamStats`). A card links to `/players/{id}` (`Players/Show`), which lists that player's streams. A stream row (shared `StreamsTable`) opens `/streams/{id}` (`Streams/Show`): stream status and actions, with events next to the paginated, searchable transcript. `?event={id}` selects an event, jumps to the transcript page with its first segment, and highlights its segments (unless `page` is given explicitly). The old `/streams/{id}/transcript` 301-redirects there. Status labels, button logic and status polling are shared in `resources/js/composables/streamStatus.ts`. The old dashboard lives on as `/overview`. The stream table (statuses, live polling of `/streams/{id}/transcription-status`, Transcribe/Extract/Delete buttons) is the shared `Components/StreamsTable.vue`, used by both the Streams page and the player page, and `Components/AddStreamModal.vue` is shared too. Stream props come from `App\Http\Resources\StreamResource`, and shared TS types live in `resources/js/types/streams.ts`. Stream actions redirect `back()` (fallback `/streams`), so they return to the page they were triggered from. Status values: `pending` = not started, `queued` = job dispatched, then `processing` → `completed`/`failed` (for both transcription and event extraction). Timeline, VideoBuilder and Settings are still placeholder routes.

**Tests:** PHPUnit feature tests only (`tests/Feature`), using `Http::fake`, `Queue::fake` and `Storage::fake`. Python tests patch `load_whisper_model`/ffmpeg and `EventExtractor._load`/`_generate` with fakes and swap in a fresh `ModelManager`, so no GPU is needed.

## TODO

Open work is tracked in `TODO.md`. Check it before starting new work and tick items off (`[x]`, move to **Klaar**) when they are done.

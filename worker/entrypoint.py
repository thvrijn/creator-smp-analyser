import json
import os
import subprocess
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
import time
from typing import Any, Callable

from faster_whisper import WhisperModel
from event_extractor import EventExtractor
from model_manager import ModelManager

STORAGE_ROOT = Path(os.getenv("WORKER_STORAGE_ROOT", "/var/www/html/storage/app/private")).resolve()
MODEL_NAME = os.getenv("WHISPER_MODEL", "small")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
LANGUAGE = os.getenv("WHISPER_LANGUAGE", "nl") or None
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")
MODEL_CACHE = os.getenv("WHISPER_MODEL_CACHE", "/worker/.cache")
FFMPEG_TIMEOUT = int(os.getenv("FFMPEG_TIMEOUT_SECONDS", "7200"))
WHISPER_CHUNK_SECONDS = max(30, float(os.getenv("WHISPER_CHUNK_SECONDS", "300")))
MODEL_IDLE_SECONDS = max(0.0, float(os.getenv("WORKER_MODEL_IDLE_SECONDS", "600")))
# Whisper and the event LLM share the GPU; only one is loaded at a time.
MODELS = ModelManager(idle_seconds=MODEL_IDLE_SECONDS)
_event_extractor = EventExtractor(MODELS)


class ProcessingError(Exception):
    pass


def resolve_stream_file(video_path: str) -> Path:
    candidate_path = Path(video_path)
    if candidate_path.is_absolute() or ".." in candidate_path.parts:
        raise ProcessingError("video_path must be a relative storage path")
    resolved = (STORAGE_ROOT / candidate_path).resolve()
    try:
        resolved.relative_to(STORAGE_ROOT)
    except ValueError as exc:
        raise ProcessingError("video_path points outside the storage directory") from exc
    if not resolved.is_file():
        raise ProcessingError(f"stream file does not exist: {video_path}")
    return resolved


def probe_duration(video_file: Path) -> float:
    command = ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", str(video_file)]
    try:
        result = subprocess.run(command, capture_output=True, text=True, timeout=60, check=False)
    except subprocess.TimeoutExpired as exc:
        raise ProcessingError("FFprobe timed out while determining stream duration") from exc
    if result.returncode != 0:
        raise ProcessingError(f"FFprobe failed: {result.stderr.strip() or 'unknown FFprobe error'}")
    try:
        return max(0.0, float(result.stdout.strip()))
    except ValueError as exc:
        raise ProcessingError("FFprobe returned an invalid stream duration") from exc


def progress_for(processed_seconds: float, duration_seconds: float) -> int:
    if duration_seconds <= 0:
        return 0
    return max(0, min(100, round((max(0.0, processed_seconds) / duration_seconds) * 100)))


def extract_audio(
    video_file: Path,
    audio_file: Path,
    on_progress: Callable[[float], None] | None = None,
    duration_seconds: float = 0.0,
    start_seconds: float = 0.0,
) -> None:
    command = [
        "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
        "-ss", str(max(0.0, start_seconds)), "-i", str(video_file),
        "-t", str(max(0.0, duration_seconds)),
        "-vn", "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", "-progress", "pipe:1", "-nostats", str(audio_file),
    ]
    try:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        assert process.stdout is not None
        for line in process.stdout:
            if line.startswith("out_time_ms=") and on_progress is not None:
                try:
                    on_progress(max(0.0, float(line.split("=", 1)[1]) / 1_000_000))
                except ValueError:
                    continue
        stderr = process.stderr.read() if process.stderr is not None else ""
        return_code = process.wait(timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired as exc:
        process.kill()
        raise ProcessingError("FFmpeg timed out while extracting audio") from exc
    if return_code != 0:
        raise ProcessingError(f"FFmpeg failed: {stderr.strip() or 'unknown FFmpeg error'}")
    if on_progress is not None and duration_seconds > 0:
        on_progress(duration_seconds)


def load_whisper_model() -> WhisperModel:
    return WhisperModel(MODEL_NAME, device=DEVICE, compute_type=COMPUTE_TYPE, download_root=MODEL_CACHE)


def unload_whisper_model(model: Any) -> None:
    ctranslate2_model = getattr(model, "model", None)
    if ctranslate2_model is not None and hasattr(ctranslate2_model, "unload_model"):
        ctranslate2_model.unload_model()


def transcribe_stream(stream_id: int, video_path: str, emit: Callable[[dict[str, Any]], None] | None = None) -> dict[str, Any]:
    # Hold Whisper for the whole stream so event requests cannot swap it out between chunks.
    with MODELS.use("whisper", load_whisper_model, unload_whisper_model) as model:
        return _transcribe_stream(model, stream_id, video_path, emit)


def _transcribe_stream(model: Any, stream_id: int, video_path: str, emit: Callable[[dict[str, Any]], None] | None) -> dict[str, Any]:
    video_file = resolve_stream_file(video_path)
    duration = probe_duration(video_file)
    send = emit or (lambda _event: None)
    send({"type": "duration", "duration_seconds": duration})
    send({"type": "stage", "stage": "extracting_audio"})

    with tempfile.TemporaryDirectory(prefix=f"creatorsmp4-{stream_id}-") as temporary_directory:
        result_segments = []
        chunk_start = 0.0
        while chunk_start < duration:
            chunk_duration = min(WHISPER_CHUNK_SECONDS, duration - chunk_start)
            audio_file = Path(temporary_directory) / f"audio-{int(chunk_start)}.wav"
            last_progress = -1

            def extraction_progress(processed: float) -> None:
                nonlocal last_progress
                global_processed = chunk_start + min(chunk_duration, max(0.0, processed))
                progress = progress_for(global_processed, duration)
                if progress != last_progress:
                    last_progress = progress
                    send({"type": "progress", "stage": "extracting_audio", "processed_seconds": global_processed, "progress": progress, "segment_count": len(result_segments)})

            extract_audio(video_file, audio_file, extraction_progress, chunk_duration, chunk_start)
            send({"type": "stage", "stage": "transcribing", "processed_seconds": chunk_start, "progress": progress_for(chunk_start, duration), "segment_count": len(result_segments)})
            try:
                segments, _info = model.transcribe(str(audio_file), language=LANGUAGE, vad_filter=True)
                for segment in segments:
                    text = segment.text.strip()
                    if not text:
                        continue
                    item = {
                        "start": chunk_start + float(segment.start),
                        "end": chunk_start + float(segment.end),
                        "text": text,
                    }
                    result_segments.append(item)
                    send({"type": "segment", **item})
                    send({"type": "progress", "stage": "transcribing", "processed_seconds": min(duration, item["end"]), "progress": progress_for(item["end"], duration), "segment_count": len(result_segments)})
            except Exception as exc:
                raise ProcessingError(f"Whisper failed: {exc}") from exc
            audio_file.unlink(missing_ok=True)
            chunk_start += chunk_duration

    send({"type": "completed", "stage": "completed", "processed_seconds": duration, "progress": 100, "segment_count": len(result_segments)})
    return {"stream_id": stream_id, "segments": result_segments}


class WorkerRequestHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/health":
            self.send_error(404, "Not found")
            return
        self.send_json({"status": "ok", "loaded_model": MODELS.loaded})

    def do_POST(self) -> None:  # noqa: N802
        if self.path not in {"/transcribe", "/extract-events"}:
            self.send_error(404, "Not found")
            return
        try:
            if self.path == "/extract-events":
                self.handle_extract_events()
            else:
                self.handle_transcribe()
        finally:
            self.close_connection = True

    def read_payload(self) -> Any:
        content_length = int(self.headers.get("Content-Length", "0"))
        return json.loads(self.rfile.read(content_length))

    def handle_extract_events(self) -> None:
        # Plain JSON endpoint: every outcome, including errors, is a complete HTTP response.
        try:
            payload = self.read_payload()
            segments = payload.get("segments", []) if isinstance(payload, dict) else None
            if not isinstance(segments, list):
                raise ValueError("segments must be a list")
        except (TypeError, ValueError) as exc:
            self.send_json_error(f"Invalid event extraction request: {exc}", status=400)
            return
        try:
            events = _event_extractor.extract(segments)
        except (ProcessingError, RuntimeError, ValueError) as exc:
            self.send_json_error(str(exc), status=422)
            return
        except Exception as exc:
            self.send_json_error(f"Worker error: {exc}", status=500)
            return
        self.send_json({"events": events})

    def handle_transcribe(self) -> None:
        # Validate before the NDJSON stream starts, so request errors get a normal HTTP status.
        try:
            payload = self.read_payload()
            stream_id, video_path = int(payload["stream_id"]), str(payload["video_path"])
        except (KeyError, TypeError, ValueError) as exc:
            self.send_json_error(f"Invalid transcription request: {exc}", status=400)
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/x-ndjson")
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        self.wfile.flush()
        try:
            transcribe_stream(stream_id, video_path, self.write_event)
        except (ProcessingError, RuntimeError, ValueError) as exc:
            self.write_error_event(str(exc))
        except Exception as exc:
            self.write_error_event(f"Worker error: {exc}")

    def send_json_error(self, message: str, status: int) -> None:
        print(f"worker: {self.path} failed ({status}): {message}", flush=True)
        self.send_json({"error": message}, status=status)

    def write_event(self, event: dict[str, Any]) -> None:
        self.wfile.write((json.dumps(event) + "\n").encode("utf-8"))
        self.wfile.flush()

    def send_json(self, payload: dict[str, Any], status: int = 200) -> None:
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def write_error_event(self, message: str) -> None:
        print(f"worker: {self.path} failed: {message}", flush=True)
        try:
            self.write_event({"type": "error", "error": message})
        except (BrokenPipeError, ConnectionResetError):
            pass

    def log_message(self, format: str, *args: Any) -> None:
        print(f"worker: {format % args}", flush=True)


def unload_idle_models() -> None:
    while True:
        time.sleep(30)
        MODELS.unload_if_idle()


def main() -> None:
    host = os.getenv("WORKER_HOST", "0.0.0.0")
    port = int(os.getenv("WORKER_PORT", "8001"))
    print(f"transcription worker listening on {host}:{port}; model={MODEL_NAME} device={DEVICE}", flush=True)
    if MODEL_IDLE_SECONDS > 0:
        Thread(target=unload_idle_models, daemon=True).start()
    ThreadingHTTPServer((host, port), WorkerRequestHandler).serve_forever()


if __name__ == "__main__":
    main()

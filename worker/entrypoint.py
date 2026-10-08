import hmac
import json
import os
import subprocess
import tempfile
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
import time
from typing import Any, Callable

from faster_whisper import WhisperModel
import diarization
import registration
from event_extractor import EventExtractor
from model_manager import ModelManager

STORAGE_ROOT = Path(os.getenv("WORKER_STORAGE_ROOT", "/var/www/html/storage/app/private")).resolve()
MODEL_NAME = os.getenv("WHISPER_MODEL", "large-v3-turbo")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
LANGUAGE = os.getenv("WHISPER_LANGUAGE", "nl") or None
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")
MODEL_CACHE = os.getenv("WHISPER_MODEL_CACHE", "/worker/.cache")
FFMPEG_TIMEOUT = int(os.getenv("FFMPEG_TIMEOUT_SECONDS", "7200"))
WHISPER_CHUNK_SECONDS = max(30, float(os.getenv("WHISPER_CHUNK_SECONDS", "300")))
MODEL_IDLE_SECONDS = max(0.0, float(os.getenv("WORKER_MODEL_IDLE_SECONDS", "600")))
# Where audio downloaded from the app is kept while it is transcribed (a worker without the app's storage).
DOWNLOAD_DIR = os.getenv("WORKER_CACHE_DIR") or None
DOWNLOAD_TIMEOUT = float(os.getenv("WORKER_DOWNLOAD_TIMEOUT_SECONDS", "60"))
# Whisper and the event LLM share the GPU; an 8 GB GPU holds one of them, a 16 GB GPU both (WORKER_MAX_LOADED_MODELS=2).
MODELS = ModelManager(idle_seconds=MODEL_IDLE_SECONDS, max_loaded=int(os.getenv("WORKER_MAX_LOADED_MODELS", "1")))
_event_extractor = EventExtractor(MODELS)


class ClientGone(Exception):
    """The app closed the connection (the job was cancelled or killed): stop working."""


class ProcessingError(Exception):
    pass


def resolve_stream_file(video_path: str) -> Path:
    candidate_path = Path(video_path)
    if candidate_path.is_absolute() or ".." in candidate_path.parts:
        raise ProcessingError("video_path moet een relatief pad binnen de opslag zijn")
    resolved = (STORAGE_ROOT / candidate_path).resolve()
    try:
        resolved.relative_to(STORAGE_ROOT)
    except ValueError as exc:
        raise ProcessingError("video_path wijst naar een plek buiten de opslagmap") from exc
    if not resolved.is_file():
        raise ProcessingError(f"streambestand bestaat niet: {video_path}")
    return resolved


def download_stream_file(stream_id: int, video_url: str, send: Callable[[dict[str, Any]], None]) -> Path:
    """Downloads the stream's audio from the app (a signed URL) into a temporary file, reporting progress."""
    if not video_url.startswith(("http://", "https://")):
        raise ProcessingError("video_url moet een http(s)-URL zijn")
    send({"type": "stage", "stage": "downloading_audio", "progress": 0})
    handle, name = tempfile.mkstemp(prefix=f"creatorsmp4-{stream_id}-", suffix=".audio", dir=DOWNLOAD_DIR)
    target = Path(name)
    try:
        with os.fdopen(handle, "wb") as file, urllib.request.urlopen(video_url, timeout=DOWNLOAD_TIMEOUT) as response:
            total = int(response.headers.get("Content-Length") or 0)
            received, last_progress = 0, -1
            while chunk := response.read(1024 * 1024):
                file.write(chunk)
                received += len(chunk)
                progress = progress_for(received, total)
                if total and progress != last_progress:
                    last_progress = progress
                    send({"type": "progress", "stage": "downloading_audio", "progress": progress})
        if total and received != total:
            raise ProcessingError(f"audio onvolledig ontvangen ({received} van {total} bytes)")
    except urllib.error.HTTPError as exc:
        target.unlink(missing_ok=True)
        raise ProcessingError(f"audio ophalen bij de app mislukt: HTTP {exc.code}") from exc
    except (urllib.error.URLError, OSError) as exc:
        target.unlink(missing_ok=True)
        raise ProcessingError(f"audio ophalen bij de app mislukt: {exc}") from exc
    except BaseException:
        target.unlink(missing_ok=True)
        raise
    print(f"worker: downloaded stream {stream_id} audio ({received / 2**20:.0f} MB)", flush=True)
    return target


def resolve_input(stream_id: int, video_path: str | None, video_url: str | None, send: Callable[[dict[str, Any]], None]) -> tuple[Path, bool]:
    """The stream's file: from the shared storage when this worker has it, else downloaded from the app.

    Returns the path and whether it is a temporary download that must be removed afterwards.
    """
    try:
        if not video_path:
            raise ProcessingError("geen video_path")
        return resolve_stream_file(video_path), False
    except ProcessingError:
        if not video_url:
            raise
    return download_stream_file(stream_id, video_url, send), True


def probe_duration(video_file: Path) -> float:
    command = ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", str(video_file)]
    try:
        result = subprocess.run(command, capture_output=True, text=True, timeout=60, check=False)
    except subprocess.TimeoutExpired as exc:
        raise ProcessingError("FFprobe deed er te lang over om de streamduur te bepalen") from exc
    if result.returncode != 0:
        raise ProcessingError(f"FFprobe mislukt: {result.stderr.strip() or 'onbekende FFprobe-fout'}")
    try:
        return max(0.0, float(result.stdout.strip()))
    except ValueError as exc:
        raise ProcessingError("FFprobe gaf een ongeldige streamduur") from exc


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
        try:
            for line in process.stdout:
                if line.startswith("out_time_ms=") and on_progress is not None:
                    try:
                        on_progress(max(0.0, float(line.split("=", 1)[1]) / 1_000_000))
                    except ValueError:
                        continue
        except BaseException:
            # E.g. ClientGone from the progress callback: don't leave FFmpeg running.
            process.kill()
            process.wait()
            raise
        stderr = process.stderr.read() if process.stderr is not None else ""
        return_code = process.wait(timeout=FFMPEG_TIMEOUT)
    except subprocess.TimeoutExpired as exc:
        process.kill()
        raise ProcessingError("FFmpeg deed er te lang over om de audio te extraheren") from exc
    if return_code != 0:
        raise ProcessingError(f"FFmpeg mislukt: {stderr.strip() or 'onbekende FFmpeg-fout'}")
    if on_progress is not None and duration_seconds > 0:
        on_progress(duration_seconds)


def transcription_spans(ranges: list[list[float]] | None, duration: float) -> list[tuple[float, float]]:
    """The parts of the file to transcribe: the given [start, end] ranges clipped to the file and merged; without ranges the whole file."""
    if not ranges:
        return [(0.0, duration)]
    merged: list[tuple[float, float]] = []
    for start, end in sorted((max(0.0, float(start)), min(duration, float(end))) for start, end in ranges):
        if end <= start:
            continue
        if merged and start <= merged[-1][1]:
            merged[-1] = (merged[-1][0], max(merged[-1][1], end))
        else:
            merged.append((start, end))
    return merged


def load_whisper_model() -> WhisperModel:
    return WhisperModel(MODEL_NAME, device=DEVICE, compute_type=COMPUTE_TYPE, download_root=MODEL_CACHE)


def unload_whisper_model(model: Any) -> None:
    ctranslate2_model = getattr(model, "model", None)
    if ctranslate2_model is not None and hasattr(ctranslate2_model, "unload_model"):
        ctranslate2_model.unload_model()


def transcribe_stream(
    stream_id: int,
    video_path: str | None,
    emit: Callable[[dict[str, Any]], None] | None = None,
    ranges: list[list[float]] | None = None,
    video_url: str | None = None,
) -> dict[str, Any]:
    send = emit or (lambda _event: None)
    # Download first (if needed), so the GPU is not held while the audio comes in.
    video_file, downloaded = resolve_input(stream_id, video_path, video_url, send)
    try:
        with tempfile.TemporaryDirectory(prefix=f"creatorsmp4-{stream_id}-") as temporary_directory:
            # With diarization the extracted audio is kept for it; Whisper is done with it by then.
            keep_audio = diarization.enabled()
            # Hold Whisper for the whole stream so event requests cannot swap it out between chunks.
            with MODELS.use("whisper", load_whisper_model, unload_whisper_model) as model:
                transcript = _transcribe_stream(model, video_file, Path(temporary_directory), send, ranges, keep_audio)
            segments = transcript["segments"]
            if keep_audio and segments:
                add_speakers(transcript, send)
            for segment in segments:
                segment.pop("_audio_start", None)
                segment.pop("_audio_end", None)
    finally:
        if downloaded:
            video_file.unlink(missing_ok=True)
    send({"type": "completed", "stage": "completed", "processed_seconds": transcript["duration"], "progress": 100, "segment_count": len(segments)})
    return {"stream_id": stream_id, "segments": segments}


def _transcribe_stream(
    model: Any,
    video_file: Path,
    temporary_directory: Path,
    send: Callable[[dict[str, Any]], None],
    ranges: list[list[float]] | None = None,
    keep_audio: bool = False,
) -> dict[str, Any]:
    # Only these parts are transcribed (e.g. the Creator SMP part of a Twitch VOD). Segment times stay file times;
    # duration and progress count the transcribed parts only.
    spans = transcription_spans(ranges, probe_duration(video_file))
    duration = sum(end - start for start, end in spans)
    send({"type": "duration", "duration_seconds": duration})
    send({"type": "stage", "stage": "extracting_audio"})

    result_segments = []
    audio_parts: list[tuple[Path, float]] = []  # the kept chunks, back to back: the audio time line diarization sees
    done = 0.0  # transcribed seconds of the spans before the current one
    for span_start, span_end in spans:
        chunk_start = span_start
        while chunk_start < span_end:
            chunk_duration = min(WHISPER_CHUNK_SECONDS, span_end - chunk_start)
            audio_file = temporary_directory / f"audio-{int(chunk_start)}.wav"
            chunk_done = done + chunk_start - span_start
            last_progress = -1

            def extraction_progress(processed: float) -> None:
                nonlocal last_progress
                global_processed = chunk_done + min(chunk_duration, max(0.0, processed))
                progress = progress_for(global_processed, duration)
                if progress != last_progress:
                    last_progress = progress
                    send({"type": "progress", "stage": "extracting_audio", "processed_seconds": global_processed, "progress": progress, "segment_count": len(result_segments)})

            extract_audio(video_file, audio_file, extraction_progress, chunk_duration, chunk_start)
            send({"type": "stage", "stage": "transcribing", "processed_seconds": chunk_done, "progress": progress_for(chunk_done, duration), "segment_count": len(result_segments)})
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
                    send({"type": "segment", **item})
                    # Where the segment is in the kept audio (chunk_done is where this chunk starts there).
                    result_segments.append({**item, "_audio_start": chunk_done + float(segment.start), "_audio_end": chunk_done + float(segment.end)})
                    processed = min(duration, chunk_done + float(segment.end))
                    send({"type": "progress", "stage": "transcribing", "processed_seconds": processed, "progress": progress_for(processed, duration), "segment_count": len(result_segments)})
            except ClientGone:
                raise
            except Exception as exc:
                raise ProcessingError(f"Whisper mislukt: {exc}") from exc
            if keep_audio:
                audio_parts.append((audio_file, chunk_duration))
            else:
                audio_file.unlink(missing_ok=True)
            chunk_start += chunk_duration
        done += span_end - span_start

    return {"segments": result_segments, "duration": duration, "audio_parts": audio_parts}


def add_speakers(transcript: dict[str, Any], send: Callable[[dict[str, Any]], None]) -> None:
    """Diarizes the kept audio and gives every segment a speaker; sends them as a `speakers` event.

    A failure here keeps the transcript: it is sent without speakers, with a warning.
    """
    duration = transcript["duration"]
    segments = transcript["segments"]
    send({"type": "stage", "stage": "diarizing", "processed_seconds": 0, "progress": 0, "segment_count": len(segments)})
    last_progress = -1

    def on_progress(fraction: float) -> None:
        nonlocal last_progress
        progress = max(0, min(100, round(fraction * 100)))
        if progress != last_progress:
            last_progress = progress
            send({"type": "progress", "stage": "diarizing", "processed_seconds": duration * fraction, "progress": progress, "segment_count": len(segments)})

    try:
        waveform = diarization.read_audio(transcript["audio_parts"])
        with MODELS.use("diarization", diarization.load_pipeline, diarization.unload_pipeline) as pipeline:
            turns, voices = diarization.diarize(pipeline, waveform, on_progress)
        del waveform
        speakers, summary = diarization.assign_speakers([(segment["_audio_start"], segment["_audio_end"]) for segment in segments], turns, voices)
    except ClientGone:
        raise
    except Exception as exc:
        message = f"Sprekerherkenning mislukt: {exc}"
        print(f"worker: {message}", flush=True)
        send({"type": "warning", "message": message})
        return
    for segment, speaker in zip(segments, speakers):
        segment["speaker"] = speaker
    print(f"worker: diarized {len(segments)} segments, {len(summary)} speakers", flush=True)
    send({"type": "speakers", "segment_speakers": speakers, "speakers": summary})


class WorkerRequestHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/health":
            self.send_error(404, "Not found")
            return
        self.send_json({"status": "ok", "name": registration.worker_name(), "loaded_models": MODELS.loaded_models, "busy": registration.ACTIVE.busy, "diarization": diarization.enabled()})

    def do_POST(self) -> None:  # noqa: N802
        if self.path not in {"/transcribe", "/extract-events"}:
            self.send_error(404, "Not found")
            return
        try:
            if not self.authorized():
                self.send_json_error("Ongeldig of ontbrekend worker-token", status=401)
                return
            with registration.ACTIVE.track():
                if self.path == "/extract-events":
                    self.handle_extract_events()
                else:
                    self.handle_transcribe()
        finally:
            self.close_connection = True

    def authorized(self) -> bool:
        """With WORKER_TOKEN set (a worker reachable over the network), only the app may send work."""
        expected = registration.token()
        if not expected:
            return True
        return hmac.compare_digest(self.headers.get("Authorization", ""), f"Bearer {expected}")

    def read_payload(self) -> Any:
        content_length = int(self.headers.get("Content-Length", "0"))
        return json.loads(self.rfile.read(content_length))

    def handle_extract_events(self) -> None:
        # Plain JSON endpoint: every outcome, including errors, is a complete HTTP response.
        try:
            payload = self.read_payload()
            segments = payload.get("segments", []) if isinstance(payload, dict) else None
            if not isinstance(segments, list):
                raise ValueError("segments moet een lijst zijn")
        except (TypeError, ValueError) as exc:
            self.send_json_error(f"Ongeldig event-extractieverzoek: {exc}", status=400)
            return
        try:
            events = _event_extractor.extract(segments)
        except (ProcessingError, RuntimeError, ValueError) as exc:
            self.send_json_error(str(exc), status=422)
            return
        except Exception as exc:
            self.send_json_error(f"Workerfout: {exc}", status=500)
            return
        self.send_json({"events": events})

    def handle_transcribe(self) -> None:
        # Validate before the NDJSON stream starts, so request errors get a normal HTTP status.
        try:
            payload = self.read_payload()
            stream_id = int(payload["stream_id"])
            video_path = payload.get("video_path")
            video_url = payload.get("video_url")
            if not video_path and not video_url:
                raise KeyError("video_path of video_url")
            video_path = str(video_path) if video_path else None
            video_url = str(video_url) if video_url else None
            ranges = payload.get("ranges")
            if ranges is not None:
                ranges = [[float(start), float(end)] for start, end in ranges]
        except (KeyError, TypeError, ValueError) as exc:
            self.send_json_error(f"Ongeldig transcriptieverzoek: {exc}", status=400)
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/x-ndjson")
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        self.wfile.flush()
        try:
            transcribe_stream(stream_id, video_path, self.write_event, ranges, video_url)
        except ClientGone:
            print(f"worker: stream {stream_id}: the app closed the connection (cancelled), stopped", flush=True)
        except (ProcessingError, RuntimeError, ValueError) as exc:
            self.write_error_event(str(exc))
        except Exception as exc:
            self.write_error_event(f"Workerfout: {exc}")

    def send_json_error(self, message: str, status: int) -> None:
        print(f"worker: {self.path} failed ({status}): {message}", flush=True)
        self.send_json({"error": message}, status=status)

    def write_event(self, event: dict[str, Any]) -> None:
        try:
            self.wfile.write((json.dumps(event) + "\n").encode("utf-8"))
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError) as exc:
            raise ClientGone() from exc

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
        except ClientGone:
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
    if not diarization.enabled():
        print("worker: speaker diarization off (no HF_TOKEN or DIARIZATION_MODEL)", flush=True)
    if MODEL_IDLE_SECONDS > 0:
        Thread(target=unload_idle_models, daemon=True).start()
    registration.start(port, lambda: MODELS.loaded)
    ThreadingHTTPServer((host, port), WorkerRequestHandler).serve_forever()


if __name__ == "__main__":
    main()

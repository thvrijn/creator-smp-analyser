"""Compares Whisper with and without the hotwords sentence on a piece of real audio, on this worker's own backend.

On the Mac that is mlx-whisper, which gets the sentence as its initial prompt (switched on here, whatever
WHISPER_MLX_INITIAL_PROMPT says); in Docker faster-whisper's hotwords. It prints per run the time, the lines, the
words, the punctuation, how often each name is spelled right, the longest run of identical lines (greedy decoding on
the Mac can loop) and how often the prompt itself ended up in the transcript ("echo"). Run it on the worker's Python:

    worker/.venv/bin/python worker/hotwords_eval.py stream8.m4a --start 13800 --length 600     # Mac
    docker compose exec -T worker python3 /worker/hotwords_eval.py /var/www/html/storage/app/private/streams/8/video/<file> --start 13800 --length 600

The defaults are the 20 minutes of stream 8 (Jeremy Frieser, day 1) used for the Docker measurements in AGENTS.md;
the audio is on the stream page's audio link (/streams/8/audio) when the file is not on this machine.
"""
import argparse
import re
import tempfile
import time
from pathlib import Path

import entrypoint

DEFAULT_SENTENCE = "In deze Nederlandse Minecraft-stream van Jeremy Frieser gaat het over CreatorSMP en Verliezersrealm, met Morrog, Tamara en Ezra."
DEFAULT_NAMES = "Morrog,Tamara,Ezra,Egbert,Verliezersrealm,CreatorSMP"


def longest_repeat(lines: list[str]) -> int:
    longest, run, previous = 0, 0, None
    for line in lines:
        run = run + 1 if line.lower() == previous else 1
        previous = line.lower()
        longest = max(longest, run)
    return longest


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("audio", type=Path)
    parser.add_argument("--start", type=float, default=13800.0)
    parser.add_argument("--length", type=float, default=600.0)
    parser.add_argument("--sentence", default=DEFAULT_SENTENCE)
    parser.add_argument("--names", default=DEFAULT_NAMES, help="comma-separated names to count")
    args = parser.parse_args()
    names = [name.strip() for name in args.names.split(",") if name.strip()]
    entrypoint.MLX_INITIAL_PROMPT = True
    # The prompt written into the transcript: its opening words, which nobody says in a stream.
    echo = " ".join(args.sentence.split()[:4])

    with tempfile.TemporaryDirectory() as directory:
        wav = Path(directory) / "sample.wav"
        entrypoint.extract_audio(args.audio, wav, None, args.length, args.start)
        model = entrypoint.load_whisper_model()
        print(f"backend {entrypoint.DEVICE}, model {entrypoint.MODEL_NAME}, {args.length:.0f} s from {args.start:.0f} s")
        list(entrypoint.whisper_segments(model, wav))  # warm-up, so the first run is not slower
        for label, sentence in (("without", None), ("with sentence", args.sentence)):
            started = time.time()
            lines = [segment.text.strip() for segment in entrypoint.whisper_segments(model, wav, sentence) if segment.text.strip()]
            elapsed = time.time() - started
            text = " ".join(lines)
            spelled = ", ".join(f"{name} {len(re.findall(re.escape(name), text))}" for name in names)
            print(
                f"{label:14} {elapsed:5.0f} s  lines {len(lines):4}  words {len(text.split()):5}  "
                f". {text.count('.'):4}  ? {text.count('?'):3}  , {text.count(','):4}  "
                f"longest repeat {longest_repeat(lines):2}  echo {text.count(echo)}  | {spelled}",
                flush=True,
            )
        entrypoint.unload_whisper_model(model)


if __name__ == "__main__":
    main()

import tempfile
import unittest
import wave
from pathlib import Path

import numpy as np

import diarization


class DiarizationTest(unittest.TestCase):
    def test_speakers_are_numbered_by_speaking_time_and_assigned_by_overlap(self) -> None:
        turns = [(0.0, 10.0, "SPEAKER_01"), (10.0, 12.0, "SPEAKER_00"), (12.0, 40.0, "SPEAKER_01"), (50.0, 55.0, "SPEAKER_02")]
        voices = {"SPEAKER_00": [0.5, 0.5], "SPEAKER_01": [1.0, 0.0], "SPEAKER_02": None}

        speakers, summary = diarization.assign_speakers([(1.0, 3.0), (9.0, 12.0), (41.0, 45.0), (49.0, 51.0)], turns, voices)

        # Numbered by speaking time: SPEAKER_01 (38 s) is 0, SPEAKER_02 (5 s) 1, SPEAKER_00 (2 s) 2.
        # A segment between turns has no speaker.
        self.assertEqual(speakers, [0, 2, None, 1])
        self.assertEqual(summary, [
            {"speaker": 0, "seconds": 38.0, "embedding": [1.0, 0.0]},
            {"speaker": 1, "seconds": 5.0, "embedding": None},
            {"speaker": 2, "seconds": 2.0, "embedding": [0.5, 0.5]},
        ])

    def test_a_segment_goes_to_the_speaker_with_the_most_overlap(self) -> None:
        turns = [(0.0, 100.0, "A"), (100.0, 103.0, "B"), (103.0, 104.0, "A")]
        speakers, _summary = diarization.assign_speakers([(99.0, 104.0)], turns, {})
        self.assertEqual(speakers, [1])

    def test_the_audio_parts_are_read_back_to_back_at_their_nominal_length(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            parts = []
            for index, (samples, duration) in enumerate([(16000, 1.0), (8000, 1.0), (40000, 2.0)]):
                path = Path(directory) / f"audio-{index}.wav"
                with wave.open(str(path), "wb") as file:
                    file.setnchannels(1)
                    file.setsampwidth(2)
                    file.setframerate(16000)
                    file.writeframes(np.full(samples, 16384, dtype=np.int16).tobytes())
                parts.append((path, duration))

            waveform = diarization.read_audio(parts)

        # The short part is padded with silence and the long one cut, so part 3 starts at 2.0 s.
        self.assertEqual(len(waveform), 4 * 16000)
        self.assertEqual(waveform.dtype, np.float32)
        self.assertEqual(float(waveform[16000 + 7999]), 0.5)
        self.assertEqual(float(waveform[16000 + 8000]), 0.0)
        self.assertEqual(float(waveform[32000]), 0.5)

    def test_the_progress_hook_weighs_the_pyannote_steps(self) -> None:
        seen: list[float] = []
        hook = diarization.progress_hook(seen.append)
        hook("segmentation", None, total=10, completed=5)
        hook("speaker_counting", None)
        hook("embeddings", None, total=4, completed=4)
        self.assertEqual(seen, [0.15, 0.95])

    @staticmethod
    def segment(words: list[str], start: float = 0.0) -> dict:
        """A segment of one word per 0.5 s from `start` (audio time = file time + 100)."""
        timed = [(start + 100 + i * 0.5, start + 100 + i * 0.5 + 0.4, " " + word) for i, word in enumerate(words)]
        return {"start": start, "end": start + len(words) * 0.5, "_audio_start": start + 100, "_words": timed}

    def test_a_segment_is_split_where_the_speaker_changes(self) -> None:
        segment = self.segment(["Ga", "jij", "mee", "naar", "de", "muur?", "Nee", "ik", "blijf", "hier."])
        turns = [(100.0, 102.95, "A"), (103.0, 106.0, "B"), (200.0, 300.0, "A")]

        splits = diarization.split_by_speaker([segment], turns)

        self.assertEqual(splits, {0: [
            {"start": 0.0, "end": 2.9, "text": "Ga jij mee naar de muur?", "speaker": 0},
            {"start": 3.0, "end": 4.9, "text": "Nee ik blijf hier.", "speaker": 1},
        ]})

    def test_a_short_burst_of_another_voice_does_not_split_a_segment(self) -> None:
        # "ja" from someone else (or diarization jitter) in the middle of the streamer's sentence.
        segment = self.segment(["We", "moeten", "nu", "ja", "echt", "naar", "binnen", "gaan."])
        turns = [(100.0, 101.45, "A"), (101.5, 101.9, "B"), (101.95, 104.0, "A"), (200.0, 300.0, "B")]

        self.assertEqual(diarization.split_by_speaker([segment], turns), {})

    def test_a_speaker_change_moves_to_a_nearby_punctuation_mark(self) -> None:
        # Diarization hears B start at "zelf"; the sentence before ends a word earlier, at "eens."
        segment = self.segment(["Kijk", "eens.", "Helemaal", "zelf", "gebakken", "enzo.", "Wauw,", "lekker", "zeg,", "echt", "bedankt."])
        turns = [(100.0, 101.45, "A"), (101.5, 103.45, "B"), (103.5, 106.0, "A"), (200.0, 300.0, "B")]

        parts = diarization.split_by_speaker([segment], turns)[0]

        self.assertEqual([(part["text"], part["speaker"]) for part in parts], [
            ("Kijk eens.", 1),
            ("Helemaal zelf gebakken enzo. Wauw,", 0),
            ("lekker zeg, echt bedankt.", 1),
        ])

    def test_a_segment_without_word_times_is_not_split(self) -> None:
        segment = {"start": 0.0, "end": 5.0, "_audio_start": 100.0, "_words": []}

        self.assertEqual(diarization.split_by_speaker([segment], [(100.0, 102.0, "A"), (102.0, 105.0, "B")]), {})


if __name__ == "__main__":
    unittest.main()

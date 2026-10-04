import unittest
from unittest.mock import patch

from event_extractor import EventExtractor, build_prompt, parse_model_output, validate_events
from model_manager import ModelManager


class EventExtractorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.segments = [
            {"index": 0, "start_time": 10.0, "end_time": 12.0, "text": "Alex is here."},
            {"index": 1, "start_time": 13.0, "end_time": 16.0, "text": "We talk to Alex."},
        ]

    def test_prompt_contains_indexes_and_timestamps(self) -> None:
        prompt = build_prompt(self.segments)
        self.assertIn("0:", prompt)
        self.assertIn("[10.000]", prompt)

    def test_valid_json_is_parsed_and_validated(self) -> None:
        payload = parse_model_output('{"events":[{"type":"player_encounter","title":"Alex","description":"Alex appears.","confidence":0.9,"segment_indexes":[0,1]}]}')
        events = validate_events(payload, self.segments)
        self.assertEqual(events, [{"type": "player_encounter", "title": "Alex", "description": "Alex appears.", "start_time": 10.0, "end_time": 16.0, "confidence": 0.9, "segment_indexes": [0, 1]}])

    def test_json_code_fence_is_supported_but_invalid_json_is_rejected(self) -> None:
        self.assertEqual(parse_model_output('```json\n{"events": []}\n```'), {"events": []})
        with self.assertRaises(ValueError):
            parse_model_output("not json")

    def test_timestamps_come_from_segments_not_from_the_model(self) -> None:
        payload = {"events": [{"type": "combat", "title": "Fight", "description": "A fight.", "start_time": 999, "end_time": 5, "confidence": 0.5, "segment_indexes": [1]}]}
        event = validate_events(payload, self.segments)[0]
        self.assertEqual((event["start_time"], event["end_time"]), (13.0, 16.0))

    def test_invalid_events_are_skipped_and_valid_ones_kept(self) -> None:
        valid = {"type": "combat", "title": "Fight", "description": "A fight.", "confidence": 0.5, "segment_indexes": [0]}
        invalid = [
            {"type": "bad", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": [0]},
            {"type": "combat", "title": "", "description": "x", "confidence": 0.5, "segment_indexes": [0]},
            {"type": "combat", "title": "x", "description": "x", "confidence": 1.2, "segment_indexes": [0]},
            {"type": "combat", "title": "x", "description": "x", "confidence": True, "segment_indexes": [0]},
            {"type": "combat", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": [2]},
            {"type": "combat", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": []},
            {"type": "combat", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": [True]},
            "not an object",
        ]
        with patch("builtins.print"):
            events = validate_events({"events": [*invalid, valid]}, self.segments)
        self.assertEqual([event["title"] for event in events], ["Fight"])

    def test_event_spanning_too_many_segments_is_skipped_as_a_chunk_summary(self) -> None:
        segments = [{"index": i, "start_time": float(i), "end_time": i + 0.5, "text": f"line {i}"} for i in range(20)]
        summary = {"type": "statement", "title": "Whole chunk", "description": "Everything.", "confidence": 0.9, "segment_indexes": list(range(13))}
        moment = {"type": "death", "title": "Dies", "description": "Dies.", "confidence": 0.9, "segment_indexes": list(range(12))}
        with patch("builtins.print"):
            events = validate_events({"events": [summary, moment]}, segments)
        self.assertEqual([event["title"] for event in events], ["Dies"])

    def test_empty_events_and_wrong_shape(self) -> None:
        self.assertEqual(validate_events(parse_model_output('{"events": []}'), self.segments), [])
        with self.assertRaises(ValueError):
            parse_model_output('{"result": []}')
        with self.assertRaises(ValueError):
            parse_model_output('Here are the events: {"events": []}')

    def test_multiple_events_are_validated_and_indexes_deduplicated(self) -> None:
        payload = {"events": [
            {"type": "player_encounter", "title": "Alex", "description": "Alex appears.", "confidence": 0.9, "segment_indexes": [0, 0]},
            {"type": "conversation", "title": "Talk", "description": "They talk.", "confidence": 0.6, "segment_indexes": [1]},
        ]}
        events = validate_events(payload, self.segments)
        self.assertEqual([event["type"] for event in events], ["player_encounter", "conversation"])
        self.assertEqual(events[0]["segment_indexes"], [0])

    def test_extract_loads_model_once_for_multiple_chunks(self) -> None:
        loads = []
        manager = ModelManager(release_memory=lambda: None)
        extractor = EventExtractor(manager)
        output = '{"events":[{"type":"player_encounter","title":"Alex","description":"Alex appears.","confidence":0.8,"segment_indexes":[0]}]}'

        def load():
            loads.append(1)
            return "model", "tokenizer"

        with patch.object(extractor, "_load", side_effect=load), patch.object(extractor, "_generate", return_value=output) as generate:
            first = extractor.extract(self.segments)
            second = extractor.extract(self.segments)

        self.assertEqual(len(loads), 1)
        self.assertEqual(generate.call_count, 2)
        self.assertEqual(first, second)
        self.assertEqual(first[0]["start_time"], 10.0)
        self.assertEqual(manager.loaded, "event_llm")

    def test_extract_skips_model_for_empty_input(self) -> None:
        extractor = EventExtractor(ModelManager(release_memory=lambda: None))
        with patch.object(extractor, "_load") as load:
            self.assertEqual(extractor.extract([]), [])
        load.assert_not_called()


if __name__ == "__main__":
    unittest.main()

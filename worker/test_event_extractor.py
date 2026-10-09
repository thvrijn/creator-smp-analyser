import unittest
from unittest.mock import patch

from event_extractor import EventExtractor, build_prompt, build_story_prompt, clean_text, known_player, parse_model_output, validate_events
from model_manager import ModelManager


class EventExtractorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.segments = [
            {"index": 0, "start_time": 10.0, "end_time": 12.0, "text": "Alex is here."},
            {"index": 1, "start_time": 13.0, "end_time": 16.0, "text": "We talk to Alex."},
        ]

    def test_prompt_contains_indexes_speakers_and_the_context(self) -> None:
        segments = [{**self.segments[0], "speaker": "Morrog"}, self.segments[1]]
        prompt = build_prompt(segments, {"streamer": "Morrog", "players": ["Alex", "Morrog"]})
        self.assertEqual(prompt.splitlines(), ["Streamer: Morrog", "Players on the server: Alex, Morrog", "0: [Morrog] Alex is here.", "1: We talk to Alex."])

    def test_valid_json_is_parsed_and_validated(self) -> None:
        payload = parse_model_output('{"events":[{"type":"player_encounter","title":"Alex","description":"Alex appears.","confidence":0.9,"segment_indexes":[0,1]}]}')
        events = validate_events(payload, self.segments)
        self.assertEqual(events, [{"type": "player_encounter", "title": "Alex", "description": "Alex appears.", "start_time": 10.0, "end_time": 16.0, "confidence": 0.9, "segment_indexes": [0, 1]}])

    def test_json_code_fence_is_supported_but_invalid_json_is_rejected(self) -> None:
        self.assertEqual(parse_model_output('```json\n{"events": []}\n```'), {"events": []})
        with self.assertRaises(ValueError):
            parse_model_output("not json")

    def test_timestamps_come_from_segments_not_from_the_model(self) -> None:
        payload = {"events": [{"type": "combat", "title": "Fight", "description": "A fight.", "start_time": 999, "end_time": 5, "confidence": 0.5, "segment_indexes": [1, 0]}]}
        event = validate_events(payload, self.segments)[0]
        self.assertEqual((event["start_time"], event["end_time"]), (10.0, 16.0))

    def test_invalid_events_are_skipped_and_valid_ones_kept(self) -> None:
        valid = {"type": "combat", "title": "Fight", "description": "A fight.", "confidence": 0.5, "segment_indexes": [0, 1]}
        invalid = [
            {"type": "bad", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": [0, 1]},
            {"type": "statement", "title": "x", "description": "x", "confidence": 0.5, "segment_indexes": [0, 1]},
            {"type": "combat", "title": "One remark", "description": "x", "confidence": 0.5, "segment_indexes": [0]},
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
        segments = [{"index": i, "start_time": float(i), "end_time": i + 0.5, "text": f"line {i}"} for i in range(250)]
        summary = {"type": "plot", "title": "Whole chunk", "description": "Everything.", "confidence": 0.9, "first_line": 0, "last_line": 200}
        moment = {"type": "death", "title": "Dies", "description": "Dies.", "confidence": 0.9, "first_line": 0, "last_line": 199}
        with patch("builtins.print"):
            events = validate_events({"events": [summary, moment]}, segments)
        self.assertEqual([event["title"] for event in events], ["Dies"])

    def test_lines_give_the_whole_range_and_bad_ranges_are_skipped(self) -> None:
        segments = [{"index": i, "start_time": float(i), "end_time": i + 0.5, "text": f"line {i}"} for i in range(10)]
        event = {"type": "teamwork", "title": "Samen minen", "description": "x", "confidence": 0.8, "first_line": 2, "last_line": 6}
        listed = {**{key: value for key, value in event.items() if key not in ("first_line", "last_line")}, "lines": [3, 4, 5, 6, 7]}
        bad = [{**event, "first_line": first, "last_line": last} for first, last in ([6, 2], [2, None], [2, 10], [True, 3], ["2", 6], [4, 4])]
        with patch("builtins.print"):
            events = validate_events({"events": [*bad, event, listed]}, segments)
        self.assertEqual([found["segment_indexes"] for found in events], [[2, 3, 4, 5, 6], [3, 4, 5, 6, 7]])
        self.assertEqual((events[0]["start_time"], events[0]["end_time"]), (2.0, 6.5))

    def test_empty_events_and_wrong_shape(self) -> None:
        self.assertEqual(validate_events(parse_model_output('{"events": []}'), self.segments), [])
        with self.assertRaises(ValueError):
            parse_model_output('{"result": []}')
        with self.assertRaises(ValueError):
            parse_model_output('Here are the events: {"events": []}')

    def test_multiple_events_are_validated_and_indexes_deduplicated(self) -> None:
        payload = {"events": [
            {"type": "player_encounter", "title": "Alex", "description": "Alex appears.", "confidence": 0.9, "segment_indexes": [1, 0, 0]},
            {"type": "conversation", "title": "Talk", "description": "They talk.", "confidence": 0.6, "segment_indexes": [0, 1]},
        ]}
        events = validate_events(payload, self.segments)
        self.assertEqual([event["type"] for event in events], ["player_encounter", "conversation"])
        self.assertEqual(events[0]["segment_indexes"], [0, 1])

    def test_extract_loads_model_once_for_multiple_chunks(self) -> None:
        loads = []
        manager = ModelManager(release_memory=lambda: None)
        extractor = EventExtractor(manager)
        output = '{"summary":"Alex komt langs.","events":[{"type":"player_encounter","title":"Alex","description":"Alex appears.","confidence":0.8,"segment_indexes":[0,1]}]}'

        def load():
            loads.append(1)
            return "model", "tokenizer"

        with patch.object(extractor, "_load", side_effect=load), patch.object(extractor, "_generate", return_value=output) as generate:
            first = extractor.extract(self.segments)
            second = extractor.extract(self.segments)

        self.assertEqual(len(loads), 1)
        self.assertEqual(generate.call_count, 2)
        self.assertEqual(first, second)
        self.assertEqual(first["events"][0]["start_time"], 10.0)
        self.assertEqual(first["summary"], "Alex komt langs.")
        self.assertEqual(manager.loaded, "event_llm")

    def test_extract_skips_model_for_empty_input(self) -> None:
        extractor = EventExtractor(ModelManager(release_memory=lambda: None))
        with patch.object(extractor, "_load") as load:
            self.assertEqual(extractor.extract([]), {"events": [], "summary": ""})
            self.assertEqual(extractor.summarize_story([{"start_time": 0, "end_time": 300, "summary": ""}], []), {"summary": "", "players": []})
        load.assert_not_called()

    def test_story_is_written_from_the_part_summaries_and_events(self) -> None:
        parts = [{"start_time": 0, "end_time": 300, "summary": "Morrog hakt hout."}, {"start_time": 300, "end_time": 600, "summary": ""}, {"start_time": 600, "end_time": 900, "summary": "Morrog ontmoet Jeremy."}]
        events = [{"start_time": 610.5, "title": "Morrog ontmoet Jeremy", "description": "Bij de spawn."}]
        prompt = build_story_prompt(parts, events, {"streamer": "Morrog"})
        self.assertEqual(prompt.splitlines(), ["Streamer: Morrog", "Parts:", "[0:00:00-0:05:00] Morrog hakt hout.", "[0:10:00-0:15:00] Morrog ontmoet Jeremy.", "Story events:", "[0:10:10] Morrog ontmoet Jeremy: Bij de spawn."])

        extractor = EventExtractor(ModelManager(release_memory=lambda: None))
        with patch.object(extractor, "_load", return_value=("model", "tokenizer")), \
                patch.object(extractor, "_complete", return_value='{"summary":"Morrog ontmoet Jeremy.","players":["Jeremy","","Spreker 3"]}'):
            story = extractor.summarize_story(parts, events, {"streamer": "Morrog"})
        self.assertEqual(story, {"summary": "Morrog ontmoet Jeremy.", "players": ["Jeremy"]})

    def test_events_with_a_quote_that_is_not_said_or_a_made_up_place_are_skipped(self) -> None:
        segments = [
            {"index": 0, "start_time": 0.0, "end_time": 2.0, "text": "Moet je uitloggen, ben je hier niet veilig dan?"},
            {"index": 1, "start_time": 2.0, "end_time": 4.0, "text": "Oh, is dat zo'n netherite template?"},
        ]
        event = {"type": "plot", "title": "Morrog deelt een geheim", "description": "Over een plek.", "confidence": 0.8, "first_line": 0, "last_line": 1}
        payload = {"events": [
            {**event, "quote": "ben je hier niet veilig dan"},
            {**event, "quote": "ik heb een geheim over een gevaarlijke plek"},
            {**event, "description": "Een gevaarlijke plek in de Nether.", "quote": "ben je hier niet veilig"},
        ]}
        self.assertEqual([e["description"] for e in validate_events(payload, segments)], ["Over een plek."])

    def test_summaries_lose_chat_talk_made_up_places_and_repeats(self) -> None:
        text = "Jeremy dankt zijn abonnees voor de subs. Jeremy en Morrog plannen een aanval op het dorp. Ze vinden een geheim in de Nether.\n\nJeremy en Morrog plannen samen een aanval op het dorp. Spreker 4 helpt met bouwen."
        self.assertEqual(clean_text(text, "plannen een aanval op het dorp, netherite"), "Jeremy en Morrog plannen een aanval op het dorp.\n\nSpreker 4 helpt met bouwen.")

    def test_names_are_matched_to_the_player_list(self) -> None:
        players = ["Egbertlive", "Jeremy Frieser", "MaikoBeukers", "Morrog"]
        self.assertEqual(known_player("Egbert", players), "Egbertlive")
        self.assertEqual(known_player("Ebertlive", players), "Egbertlive")
        self.assertEqual(known_player("Maiko Beukers", players), "MaikoBeukers")
        self.assertIsNone(known_player("Real Missa", players))

    def test_the_story_is_written_per_hour_and_cleaned(self) -> None:
        parts = [{"start_time": 0, "end_time": 300, "summary": "Morrog hakt hout."}, {"start_time": 3700, "end_time": 4000, "summary": "Morrog ontmoet Jeremy bij de spawn."}]
        answers = iter([
            '{"summary":"Morrog hakt hout in het bos.","players":["Morrog"]}',
            '{"summary":"Morrog ontmoet Jeremy bij de spawn. Morrog ontmoet Jeremy bij de spawn. Ze zoeken de Nether.","players":["Jeremy","Real Missa","Spreker 3"]}',
        ])
        extractor = EventExtractor(ModelManager(release_memory=lambda: None))
        with patch.object(extractor, "_load", return_value=("model", "tokenizer")), \
                patch.object(extractor, "_complete", side_effect=lambda *args, **kwargs: next(answers)) as complete:
            story = extractor.summarize_story(parts, [], {"streamer": "Morrog", "players": ["Morrog", "Jeremy"]})
        self.assertEqual(complete.call_count, 2)
        self.assertEqual(story, {"summary": "Morrog hakt hout in het bos.\n\nMorrog ontmoet Jeremy bij de spawn.", "players": ["Jeremy"]})


if __name__ == "__main__":
    unittest.main()

"""Evaluates the event-extraction prompt on synthetic Dutch SMP chunks with known story moments and known noise.

The prompt should report story events (players meeting, working together, fighting, trading, betraying) and leave
out chat talk and routine gameplay. Loads the real model on the GPU (stop other GPU work first). Run with
`make prompt-eval`, optionally with a JSON file of real chunks: {"context": {...}, "chunks": [[{index, start_time,
end_time, text, speaker}, ...], ...]} (or a plain list of chunks):
    docker compose exec -T worker python3 /worker/prompt_eval.py /path/to/chunks.json
With real chunks it also writes the storyline from their summaries, like the app does at the end of an analysis.
"""
import json
import sys
import time
from collections import Counter

import event_extractor
from event_extractor import EventExtractor, clean_text, parse_model_output, parse_summary, validate_events
from model_manager import ModelManager

PROMPTS = {"current": event_extractor.SYSTEM_PROMPT}
CONTEXT = {"streamer": "Lisa", "players": ["Lisa", "Sam", "Kevin", "Alex", "DonKaaklijn", "Morrog", "Jeremy"]}


def seg(start: float, speaker: str, text: str, length: float = 3.5) -> dict:
    return {"start_time": start, "end_time": start + length, "speaker": speaker, "text": text}


# Per case: the chunk, the story moments it must find (accepted types, lines), and the noise lines (chat talk, routine
# gameplay) that must not become events.
SYNTHETIC = {
    "encounter_teamwork": ([
        seg(100, "Lisa", "Oké chat, bedankt voor de follow Mark!"),
        seg(104, "Lisa", "Ik ga even wat bomen hakken voor hout."),
        seg(108, "Lisa", "Mijn axe is bijna kapot, balen."),
        seg(112, "Lisa", "Wacht, daar komt iemand aanlopen. Is dat Alex?"),
        seg(116, "Spreker 2", "Hé Lisa! Ik zoek al de hele dag naar je."),
        seg(120, "Lisa", "Alex! Wat doe jij hier?"),
        seg(124, "Spreker 2", "Ik heb een grote cave gevonden, ga je mee minen?"),
        seg(128, "Lisa", "Ja, is goed, ik pak mijn spullen."),
        seg(132, "Lisa", "We gaan samen de cave in, Alex loopt voorop met een fakkel."),
        seg(136, "Spreker 2", "Kijk, iron, heel veel iron!"),
        seg(140, "Lisa", "Chat vraagt hoe laat het is, het is half negen."),
    ], [
        ({"player_encounter", "teamwork"}, {3, 4, 5, 6, 7, 8}),
    ], {0, 1, 2, 10}),
    "betrayal_fight": ([
        seg(300, "Lisa", "Kevin zei dat hij mijn kist zou bewaken."),
        seg(304, "Lisa", "Maar kijk, de kist is leeg! Al mijn diamonds zijn weg."),
        seg(308, "Lisa", "Daar loopt Kevin, met mijn diamond chestplate aan!"),
        seg(312, "Kevin", "Haha, sorry Lisa, business is business."),
        seg(316, "Lisa", "Kevin, kom hier! Ik sla hem met mijn zwaard."),
        seg(320, "Lisa", "Ik heb Kevin gekilld, mijn spullen liggen op de grond."),
        seg(324, "Lisa", "Wat een verrader. Oké chat, even rustig worden."),
        seg(328, "Lisa", "Bedankt voor de vijf subs, Daan!"),
    ], [
        ({"conflict", "combat", "death", "player_encounter"}, {1, 2, 3}),
        ({"combat", "death", "conflict"}, {4, 5}),
    ], {7}),
    "routine_and_chat_only": ([
        seg(500, "Lisa", "Hallo hallo chat, welkom bij de stream."),
        seg(504, "Lisa", "Bedankt voor de follow, Lisa!"),
        seg(508, "Lisa", "Ik ga eerst even een diamond pickaxe craften."),
        seg(512, "Lisa", "Zo, hij is gemaakt. Nu nog wat coal smelten."),
        seg(516, "Lisa", "Mijn pickaxe gaat kapot, ik moet een nieuwe maken."),
        seg(520, "Lisa", "Even mijn inventory sorteren, wat een rommel."),
        seg(524, "Lisa", "Wat zeg je, Mark? Nee ik heb nog niet gegeten."),
        seg(528, "Lisa", "Ik hak nog even een paar bomen."),
        seg(532, "Lisa", "Er komt een zombie aan, hebbes."),
        seg(536, "Lisa", "Ik ga even water pakken, ben zo terug."),
    ], [], set(range(10))),
    "trade_and_plot": ([
        seg(700, "Lisa", "Zo, ik ben bij het dorp van Sam."),
        seg(704, "Sam", "Lisa, heb je de emeralds bij je?"),
        seg(708, "Lisa", "Ja, twintig emeralds, zoals afgesproken."),
        seg(712, "Sam", "Dan krijg jij dit mending boek van mij."),
        seg(716, "Lisa", "Deal! Bedankt Sam."),
        seg(720, "Sam", "Trouwens, Kevin wil morgen het kasteel van Morrog aanvallen."),
        seg(724, "Lisa", "Wat? Dan moeten we Morrog waarschuwen!"),
        seg(728, "Sam", "Niet zeggen dat je het van mij hebt."),
        seg(732, "Lisa", "Chat, wat vinden jullie, moet ik het Morrog vertellen?"),
    ], [
        ({"trade"}, {1, 2, 3, 4}),
        ({"plot", "conversation", "conflict"}, {5, 6, 7}),
    ], set()),
    "misspelled_player": ([
        seg(900, "Lisa", "Wie is dat daar bij de spawn?"),
        seg(904, "Spreker 3", "Hoi, ik ben Don K. Klein, ik ben nieuw hier."),
        seg(908, "Lisa", "Welkom Don! Zal ik je de spawn laten zien?"),
        seg(912, "Spreker 3", "Graag, ik weet nog niet waar ik moet bouwen."),
        seg(916, "Lisa", "Kom, ik laat je een mooie plek bij de rivier zien."),
    ], [
        ({"player_encounter", "teamwork", "conversation"}, {1, 2, 3, 4}),
    ], set()),
}


def indexed(chunk: list[dict]) -> list[dict]:
    return [{"index": i, **segment} for i, segment in enumerate(chunk)]


def run(extractor: EventExtractor, model, tokenizer, chunk: list[dict], context: dict) -> tuple[list[dict], str, str]:
    raw = extractor._generate(model, tokenizer, chunk, context)
    try:
        payload = parse_model_output(raw)
        return validate_events(payload, chunk), clean_text(parse_summary(payload), " ".join(segment["text"] for segment in chunk)), raw
    except ValueError as exc:
        return [], "", f"INVALID: {exc}: {raw[:200]}"


def load_real(path: str) -> tuple[dict, list[list[dict]]]:
    data = json.load(open(path))
    if isinstance(data, list):
        return {}, data
    return data.get("context", {}), data["chunks"]


def main() -> None:
    real_context, real_chunks = load_real(sys.argv[1]) if len(sys.argv) > 1 else ({}, [])
    extractor = EventExtractor(ModelManager(release_memory=lambda: None))
    model, tokenizer = extractor._load()
    for name, prompt in PROMPTS.items():
        event_extractor.SYSTEM_PROMPT = prompt
        started = time.time()
        print(f"\n=========== {name} ===========")
        hits = expected_total = noise_events = extra_events = 0
        for label, (chunk, expected, noise) in SYNTHETIC.items():
            events, summary, raw = run(extractor, model, tokenizer, indexed(chunk), CONTEXT)
            expected_total += len(expected)
            # A moment counts as found when an event covers most of its lines (whatever the type).
            matched = [any(len(set(e["segment_indexes"]) & want) * 2 >= len(want) for e in events) for _, want in expected]
            typed = [any(e["type"] in types and set(e["segment_indexes"]) & want for e in events) for types, want in expected]
            # An event mostly on chat or routine lines is noise.
            noisy = [e for e in events if len(set(e["segment_indexes"]) & noise) * 2 > len(e["segment_indexes"])]
            hits += sum(matched)
            noise_events += len(noisy)
            extra_events += max(0, len(events) - len(expected) - len(noisy))
            print(f"  [{label}] expected {len(expected)}, matched {sum(matched)} (type right {sum(typed)}), got {len(events)}, noise {len(noisy)}" + ("" if not raw.startswith("INVALID") else " " + raw))
            for e in events:
                print(f"      {e['type']:<16} segs={e['segment_indexes']} conf={e['confidence']:.2f} {e['title']}")
            print(f"      summary: {summary or '(leeg)'}")
        print(f"  SUMMARY {name}: story moments {hits}/{expected_total}, noise events {noise_events}, extra events {extra_events}, {time.time() - started:.0f}s")

        if not real_chunks:
            print("  [no real chunks given]")
            continue
        print("  [real chunks]")
        started = time.time()
        parts, all_events, sizes, types, invalid = [], [], [], Counter(), 0
        for number, chunk in enumerate(real_chunks):
            chunk_started = time.time()
            events, summary, raw = run(extractor, model, tokenizer, chunk, real_context)
            invalid += raw.startswith("INVALID")
            print(f"    c{number} [{event_extractor.clock(chunk[0]['start_time'])}-{event_extractor.clock(chunk[-1]['end_time'])}] {len(chunk)} lines, {len(events)} events, {time.time() - chunk_started:.0f}s" + (" " + raw if raw.startswith("INVALID") else ""))
            print(f"      summary: {summary or '(leeg)'}")
            for e in events:
                sizes.append(len(e["segment_indexes"]))
                types[e["type"]] += 1
                print(f"      {e['type']:<16} {len(e['segment_indexes']):>2} segs conf={e['confidence']:.2f} {e['title']} — {e['description']}")
                all_events.append({"start_time": chunk[e["segment_indexes"][0]]["start_time"], "title": e["title"], "description": e["description"]})
            if summary:
                parts.append({"start_time": chunk[0]["start_time"], "end_time": chunk[-1]["end_time"], "summary": summary})
        story_started = time.time()
        # The model is loaded here directly, not through the ModelManager, so write the story with it too.
        try:
            story = extractor.write_story(model, tokenizer, parts, all_events, real_context)
        except ValueError as exc:
            story = {"summary": f"INVALID: {exc}", "players": []}
        print(f"  STORY ({time.time() - story_started:.0f}s): {story.get('summary')}\n  players: {story.get('players')}")
        print(f"  REAL {name}: {len(all_events)} events in {len(real_chunks)} chunks, segs/event avg {sum(sizes) / len(sizes) if sizes else 0:.1f} max {max(sizes, default=0)}, types {dict(types)}, invalid chunks {invalid}, {time.time() - started:.0f}s")


if __name__ == "__main__":
    main()

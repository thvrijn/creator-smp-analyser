"""Evaluates the event-extraction prompt on synthetic Dutch SMP chunks with known moments.

Loads the real model on the GPU (stop other GPU work first). Run with `make prompt-eval`, optionally with
a JSON file of real chunks (a list of chunks; each chunk a list of {index, start_time, end_time, text}):
    docker compose exec -T worker python3 /worker/prompt_eval.py /path/to/chunks.json
"""
import json
import sys
import time
from collections import Counter

import event_extractor
from event_extractor import EventExtractor, parse_model_output, validate_events
from model_manager import ModelManager

PROMPTS = {"current": event_extractor.SYSTEM_PROMPT}


def seg(start: float, text: str, length: float = 3.5) -> dict:
    return {"start_time": start, "end_time": start + length, "text": text}


SYNTHETIC = {
    "encounter_death": ([
        seg(100, "Oké chat, we gaan even verder met de mine."),
        seg(104, "Ik heb nog steeds geen iron, dat is echt irritant."),
        seg(108, "Wacht, volgens mij zit daar iemand bij de ingang."),
        seg(112, "Oh ja, dat is Alex! Hoi Alex!"),
        seg(116, "Alex zegt dat hij op zoek is naar diamonds voor zijn beacon."),
        seg(120, "Ik dacht dat jij bij het dorp zat, Alex?"),
        seg(124, "Hij zegt dat het dorp is afgebrand. Wat? Door wie dan?"),
        seg(128, "Oké, ik ga weer verder, doei Alex."),
        seg(132, "Hmm, wat is dat geluid. Sssss."),
        seg(136, "Pas op, een creeper! Nee nee nee!"),
        seg(140, "Ik ben dood. Al mijn spullen liggen in de mine."),
        seg(144, "Oké, dat was dom van mij, ik moet terug om mijn spullen te halen."),
    ], [
        ({"player_encounter", "conversation"}, {3, 4}),
        ({"death", "combat"}, {9, 10}),
    ]),
    "build_discovery": ([
        seg(300, "Even kijken chat, wat zeggen jullie."),
        seg(304, "Ja ik heb vandaag goed geslapen, dank je."),
        seg(308, "Oké we gaan nu eindelijk de toren afmaken."),
        seg(312, "Ik zet het laatste stuk van het dak erop, kijk."),
        seg(316, "Klaar! De wizard toren is af, dit was echt drie streams werk."),
        seg(320, "Ik ga even naar beneden de cave in voor wat coal."),
        seg(324, "Wacht. Is dat... DIAMONDS! Vier stuks!"),
        seg(328, "Dat zijn mijn eerste diamonds in deze wereld, eindelijk!"),
        seg(332, "Even water drinken hoor."),
        seg(336, "Oké waar waren we gebleven."),
    ], [
        ({"building"}, {3, 4}),
        ({"discovery", "item"}, {6, 7}),
    ]),
    "item_trade": ([
        seg(700, "Zo, terug in de base."),
        seg(704, "Ik ga eerst even een diamond pickaxe craften met die diamonds."),
        seg(708, "Yes, hij is gemaakt! Eindelijk een diamond pickaxe."),
        seg(712, "Nu ga ik naar de villagers voor een mending boek."),
        seg(716, "Deze librarian heeft mending voor tweeëntwintig emeralds."),
        seg(720, "Gekocht! Mending is van mij."),
        seg(724, "Chat vraagt of ik moe ben, nee hoor."),
    ], [
        ({"item"}, {1, 2}),
        ({"item"}, {4, 5}),
    ]),
    "grief_fight": ([
        seg(900, "Wat is er met mijn huis gebeurd?!"),
        seg(904, "Iemand heeft mijn hele huis opgeblazen met TNT."),
        seg(908, "Er ligt overal lava, alles is weg."),
        seg(912, "Daar loopt Kevin weg, hij heeft TNT in zijn hand!"),
        seg(916, "Kevin, kom hier! Ik sla hem met mijn zwaard."),
        seg(920, "Ik heb Kevin gekilld, wat een idioot."),
        seg(924, "Oké, even rustig worden chat."),
    ], [
        ({"destruction"}, {1, 2}),
        ({"combat", "death", "player_encounter"}, {4, 5}),
    ]),
    "nether_portal": ([
        seg(1100, "Vandaag gaan we eindelijk naar de nether."),
        seg(1104, "Ik zet de laatste obsidian neer en steek hem aan."),
        seg(1108, "De nether portal werkt! Kijk hoe mooi."),
        seg(1112, "Oké, we gaan erdoor."),
        seg(1116, "Oh nee, een ghast schiet op mij!"),
        seg(1120, "Ik schiet hem terug met mijn boog, hebbes, hij is dood."),
    ], [
        ({"building", "discovery"}, {1, 2}),
        ({"combat"}, {4, 5}),
    ]),
    "talk_with_gameplay": ([
        seg(1300, "Mijn tip voor jullie: bouw altijd je farm dicht bij je base."),
        seg(1304, "Dan hoef je nooit ver te lopen voor eten."),
        seg(1308, "Ik ga nu naar de end city die ik gisteren vond."),
        seg(1312, "Ik open de kist en... een elytra! Ik heb een elytra!"),
        seg(1316, "Dit is de beste dag ooit."),
    ], [
        ({"statement"}, {0, 1}),
        ({"item", "discovery"}, {3}),
    ]),
    "filler_only": ([
        seg(500, "Hallo hallo chat, welkom bij de stream."),
        seg(504, "Bedankt voor de follow, Lisa!"),
        seg(508, "Even kijken of het geluid goed staat."),
        seg(512, "Hoor je me goed? Ja? Top."),
        seg(516, "Ik ga even water pakken, ben zo terug."),
        seg(520, "Zo, ik ben er weer."),
        seg(524, "Wat zeg je, Mark? Nee ik heb nog niet gegeten."),
        seg(528, "Oké, laten we zo beginnen."),
    ], []),
}


def indexed(chunk: list[dict]) -> list[dict]:
    return [{"index": i, **segment} for i, segment in enumerate(chunk)]


def run(extractor: EventExtractor, model, tokenizer, chunk: list[dict]) -> tuple[list[dict], str]:
    raw = extractor._generate(model, tokenizer, chunk)
    try:
        return validate_events(parse_model_output(raw), chunk), raw
    except ValueError as exc:
        return [], f"INVALID: {exc}: {raw[:200]}"


def main() -> None:
    variants = list(PROMPTS)
    stream41 = json.load(open(sys.argv[1])) if len(sys.argv) > 1 else []
    extractor = EventExtractor(ModelManager(release_memory=lambda: None))
    model, tokenizer = extractor._load()
    for name in variants:
        event_extractor.SYSTEM_PROMPT = PROMPTS[name]
        started = time.time()
        print(f"\n=========== {name} ===========")
        hits = expected_total = false_events = type_hits = extra_events = 0
        for label, (chunk, expected) in SYNTHETIC.items():
            events, raw = run(extractor, model, tokenizer, indexed(chunk))
            expected_total += len(expected)
            # A moment counts as found when an event covers its lines, whatever the type; the type is scored separately.
            matched = [any(set(e["segment_indexes"]) & want for e in events) for types, want in expected]
            typed = [any(e["type"] in types and set(e["segment_indexes"]) & want for e in events) for types, want in expected]
            hits += sum(matched)
            type_hits += sum(typed)
            extra_events += max(0, len(events) - len(expected)) if expected else 0
            if not expected:
                false_events += len(events)
            print(f"  [{label}] expected {len(expected)}, matched {sum(matched)}, got {len(events)}" + ("" if events or not raw.startswith("INVALID") else " " + raw))
            for e in events:
                print(f"      {e['type']:<16} segs={e['segment_indexes']} conf={e['confidence']:.2f} {e['title']}")
        per_chunk, seg_counts, types, invalid = [], [], Counter(), 0
        print("  [real chunks]" if stream41 else "  [no real chunks given]")
        for number, chunk in enumerate(stream41):
            events, raw = run(extractor, model, tokenizer, chunk)
            invalid += raw.startswith("INVALID")
            per_chunk.append(len(events))
            for e in events:
                seg_counts.append(len(e["segment_indexes"]))
                types[e["type"]] += 1
                print(f"      c{number} {e['type']:<16} {len(e['segment_indexes']):>2}/{len(chunk)} segs conf={e['confidence']:.2f} {e['title']}")
        avg_segs = sum(seg_counts) / len(seg_counts) if seg_counts else 0
        capped = [count for count in seg_counts if count <= 12]
        print(f"  with 12-segment cap: {len(capped)} events kept, avg segs/event {sum(capped) / len(capped) if capped else 0:.1f}")
        print(f"  SUMMARY {name}: synthetic recall {hits}/{expected_total} (type right {type_hits}), extra events {extra_events}, false events on filler {false_events}, "
              f"real chunk events {sum(per_chunk)} (per chunk {per_chunk}), avg segs/event {avg_segs:.1f}, max {max(seg_counts, default=0)}, "
              f"invalid chunks {invalid}, types {dict(types)}, {time.time() - started:.0f}s")


if __name__ == "__main__":
    main()

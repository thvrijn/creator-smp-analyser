import difflib
import json
import os
import re
from typing import Any

from model_manager import ModelManager

EVENT_TYPES = {
    "player_encounter",
    "conversation",
    "teamwork",
    "conflict",
    "combat",
    "death",
    "trade",
    "discovery",
    "building",
    "destruction",
    "plot",
    "other",
}

# Story events only: the streams of ~90 roleplaying players together form one storyline of the server, so chat talk and
# routine gameplay are left out. Measure changes with `make prompt-eval`.
SYSTEM_PROMPT = """You turn one part of a Minecraft roleplay SMP livestream transcript into story events. About 90 players share one server; each streams their own POV, and together their streams form one storyline of the server. The speech is Dutch (sometimes English) from automatic speech recognition: expect odd wording and misspelled names. A line may start with who speaks: the streamer, another player recognised by voice, or "Spreker n" (an unknown voice, often another player in the game).

Only report what happens IN THE GAME and matters for the story of the server. Story events are for example:
- the streamer meets another player, or players join up or split up
- players do something together: go mining or exploring, build, prepare a raid
- an argument, threat, betrayal, alliance, deal or promise between players
- a fight, a player killing another player, a death that matters
- giving, trading or stealing something important between players
- discovering an important place or secret, finishing or revealing a major build, destroying or griefing something
- roleplay and plot: a decision, plan, rumour, mystery or announcement that affects other players or the server

Never an event, leave these out entirely:
- talking to chat or viewers: greetings, questions, thanks, subs, donations, follows, the stream itself, settings, real life, food, breaks
- routine gameplay alone: chopping trees, mining, farming, crafting, smelting, sorting the inventory, a tool breaking, eating, walking around, ordinary mobs
- commentary, jokes or complaining while nothing happens in the game
- small talk between players: how they look, feel or play, the stream, the event in general

Rules:
- Fewer, bigger events, usually 0 to 3 per part. One event covers a whole happening from its first to its last line (e.g. the whole meeting and talk with a player), not each sentence.
- first_line and last_line: two numbers, the line where the happening starts and the line where it ends (never a list of lines). At least 2 lines apart from each other or more: a single remark is not an event.
- Never one event for the whole part: the summary is for that. When the whole part is one long talk, report only the moments in it where something is decided, agreed, revealed or happens, each from its first to its last line.
- A part without anything story-worthy returns no events. That is normal: most of a stream is routine.
- Name every player involved. Use the spelling of the player list when the transcript misspells a name (e.g. "Don K. Klein" is DonKaaklijn). An unknown voice that matters is written exactly as given, e.g. "Spreker 2": the user names it later.
- Only what is actually said in the lines. Never invent or guess players, items, places, secrets, plans or outcomes, and never add a place or thing that is not named in the lines. When you are not sure what happens, leave it out.
- quote: the words from one of the event's lines that show best what happens, copied exactly (max 15 words).
- title: Dutch, max 8 words, who and what (e.g. "Morrog en Jeremy gaan samen de mine in"). description: 1-3 Dutch sentences about what happens in the game. Keep game terms in English as they are said.
- type: player_encounter (meets another player), conversation (an in-game talk where something is decided, agreed or revealed; never small talk about skins, outfits, the stream or how people feel), teamwork (players do something together), conflict (argument, threat, betrayal, theft), combat (a fight), death (someone dies), trade (giving or trading something important between players), discovery (an important place or secret), building (a major build finished or revealed), destruction (something destroyed or griefed), plot (roleplay: a decision, plan, rumour or announcement that affects the server), other.
- confidence: 0.9+ when explicit and clear, 0.6-0.8 when likely, 0.4-0.6 when vague.
- summary: 1-3 Dutch sentences about what the streamer does in the game in this part (who, what, where), only from what is said. Never about chat, viewers, subs, donations, alerts, the stream, the microphone or real life. An empty string when nothing happens in the game.

Example input:
Streamer: Lisa
Players on the server: Lisa, Sam, Kevin
0: [Lisa] Oké chat, bedankt voor de sub!
1: [Lisa] Ik hak even wat bomen voor hout.
2: [Lisa] Kijk, daar komt Sam aanlopen.
3: [Spreker 2] Hé Lisa, ga je mee naar de grot bij de rivier?
4: [Lisa] Ja is goed, ik pak mijn zwaard.
5: [Lisa] We gaan samen naar beneden, Sam loopt voorop.
6: [Lisa] Mijn pickaxe is kapot, balen.
Example output:
{"summary":"Lisa hakt hout en wordt opgezocht door Sam. Samen gaan ze naar de grot bij de rivier.","events":[{"type":"teamwork","title":"Lisa gaat met Sam de grot in","description":"Sam komt Lisa ophalen en vraagt of ze meegaat naar de grot bij de rivier. Samen gaan ze naar beneden.","confidence":0.9,"first_line":2,"last_line":5,"quote":"ga je mee naar de grot bij de rivier?"}]}

Return only JSON with exactly these keys, nothing else:
{"summary":"...","events":[{"type":"...","title":"...","description":"...","confidence":0.0,"first_line":0,"last_line":1,"quote":"..."}]}
"""

STORY_PROMPT = """You write one chapter (about an hour) of the storyline of one player's livestream on a Minecraft roleplay SMP (about 90 players, each streaming their own POV). You get summaries of that hour's parts and its story events, in time order, all about what happened in the game.

Write in Dutch what the streamer did and experienced in the game in this hour: who they met, worked with, fought or argued with, what they found, built, traded or decided. Tell it in time order.
- Tell each happening once. Parts overlap, so the same happening is often in two or three parts: merge them into one sentence. Never repeat a sentence or the same phrase.
- Be concrete: what exactly was found, decided or done, and by whom. No vague sentences like "ze bespreken plannen" when the input says which plan.
- Only facts from the input. Never add places, items, secrets or outcomes that are not in it.
- Leave out chat, viewers, subs, donations and the stream itself.
- Name players exactly as given. "Spreker n" is an unknown voice: write it exactly like that (e.g. "Spreker 6", the user names it later) and never list it as a player.
- Short when little happens: one sentence is fine.

Return only JSON with exactly these keys, nothing else:
{"summary":"<this hour's storyline: 1-5 sentences, one paragraph>","players":["<other players the streamer dealt with in this hour>"]}
"""

# The story is written per hour: one long call over a whole stream (often 70+ parts) made the model repeat itself.
CHAPTER_SECONDS = int(os.getenv("EVENT_STORY_CHAPTER_SECONDS", "3600"))

# Places and things the model tends to bring in from its own Minecraft knowledge. A text that names one of them while the
# transcript never does is made up (e.g. "a secret in the Nether" on a server without a Nether).
GAME_TERMS = [
    "nether", "the end", "ender dragon", "enderdragon", "end portal", "end city", "wither", "bastion", "stronghold",
    "fortress", "piglin", "blaze", "ghast", "elytra", "warden", "ancient city", "trial chamber", "woodland mansion",
    "ocean monument", "portal",
]
# Chat and stream talk, never part of the game's story.
CHAT_PATTERN = re.compile(
    r"\b(chat|viewers?|kijkers?|subs?|subscri\w*|abonnee\w*|donati\w*|gedoneerd|gifted|follow\w*|alerts?|(?:live)?stream\w*|audio\w*|geluidsprobl\w*|badges?|emotes?|microfoon\w*|camera|record)\b",
    re.IGNORECASE,
)
UNKNOWN_SPEAKER = re.compile(r"(?i)\bspreker \d+\b")


def build_prompt(segments: list[dict[str, Any]], context: dict[str, Any] | None = None) -> str:
    context = context or {}
    header = []
    if context.get("streamer"):
        header.append(f"Streamer: {context['streamer']}")
    if context.get("players"):
        header.append("Players on the server: " + ", ".join(str(name) for name in context["players"]))
    lines = [
        f"{index}: " + (f"[{segment['speaker']}] " if segment.get("speaker") else "") + str(segment["text"]).strip()
        for index, segment in enumerate(segments)
    ]
    return "\n".join([*header, *lines])


def ungrounded_terms(text: str, source: str) -> list[str]:
    """The game terms in text that the source never says (as a whole word: "netherite" is not "nether")."""
    said = source.lower()
    return [term for term in GAME_TERMS if re.search(rf"\b{term}\b", text, re.IGNORECASE) and not re.search(rf"\b{term}\b", said)]


def sentences(text: str) -> list[str]:
    return [sentence for sentence in re.split(r"(?<=[.!?])\s+", text.strip()) if sentence.strip()]


def word_pairs(text: str) -> set[tuple[str, str]]:
    words = re.findall(r"\w+", text.lower())
    return set(zip(words, words[1:]))


def is_repeat(sentence: str, earlier: list[set[tuple[str, str]]]) -> bool:
    """A sentence most of whose word pairs were already said is the same happening told again."""
    pairs = word_pairs(sentence)
    return bool(pairs) and any(len(pairs & before) >= 0.6 * len(pairs) for before in earlier)


def clean_text(text: str, source: str) -> str:
    """Drops sentences about chat or the stream, sentences with made-up game terms, and sentences repeating an earlier
    one. "Spreker n" stays: the stream page makes it clickable, to say who that voice is."""
    kept: list[str] = []
    seen: list[set[tuple[str, str]]] = []
    for paragraph in re.split(r"\n\s*\n", text.strip()):
        lines = []
        for sentence in sentences(paragraph):
            if CHAT_PATTERN.search(sentence) or ungrounded_terms(sentence, source) or is_repeat(sentence, seen):
                continue
            seen.append(word_pairs(sentence))
            lines.append(sentence)
        if lines:
            kept.append(" ".join(lines))
    return "\n\n".join(kept)


def quote_found(quote: str, text: str) -> bool:
    """The event's quote is said in its lines: most of its words, in order, as speech recognition wrote them."""
    pairs = word_pairs(quote)
    if not pairs:
        words = set(re.findall(r"\w+", quote.lower()))
        return bool(words) and words <= set(re.findall(r"\w+", text.lower()))
    return len(pairs & word_pairs(text)) >= 0.6 * len(pairs)


def known_player(name: str, players: list[str]) -> str | None:
    """The player on the server list that name means, in the list's spelling ("Egbert" and "egbertlive" are
    Egbertlive); None for a name that is nobody on the server (a viewer who donated, a made-up name)."""
    def key(value: str) -> str:
        return re.sub(r"[^a-z0-9]", "", value.lower())

    wanted = key(name)
    if len(wanted) < 3:
        return None
    best, best_score = None, 0.0
    for player in players:
        candidate = key(player)
        if not candidate:
            continue
        score = difflib.SequenceMatcher(None, wanted, candidate).ratio()
        if len(wanted) >= 4 and (candidate.startswith(wanted) or wanted.startswith(candidate)):
            score = max(score, 0.9)
        if score > best_score:
            best, best_score = player, score
    return best if best_score >= 0.8 else None


def story_players(names: list[Any], context: dict[str, Any]) -> list[str]:
    players = [str(player) for player in context.get("players") or []]
    streamer = str(context.get("streamer") or "")
    result: list[str] = []
    for name in names:
        name = str(name).strip()
        if not name or UNKNOWN_SPEAKER.fullmatch(name):
            continue
        # Without a player list there is nothing to check the names against.
        player = known_player(name, players) if players else name
        if player and player != streamer and player not in result:
            result.append(player)
    return result


def chapters(parts: list[dict[str, Any]], events: list[dict[str, Any]]) -> list[tuple[list[dict[str, Any]], list[dict[str, Any]]]]:
    """The parts and events per hour of the stream (by start time), skipping hours without either."""
    grouped: dict[int, tuple[list[dict[str, Any]], list[dict[str, Any]]]] = {}
    for part in parts:
        if str(part.get("summary", "")).strip():
            grouped.setdefault(int(float(part["start_time"]) // CHAPTER_SECONDS), ([], []))[0].append(part)
    for event in events:
        grouped.setdefault(int(float(event["start_time"]) // CHAPTER_SECONDS), ([], []))[1].append(event)
    return [grouped[hour] for hour in sorted(grouped)]


def build_story_prompt(parts: list[dict[str, Any]], events: list[dict[str, Any]], context: dict[str, Any] | None = None) -> str:
    context = context or {}
    lines = [f"Streamer: {context['streamer']}"] if context.get("streamer") else []
    lines.append("Parts:")
    lines += [f"[{clock(part['start_time'])}-{clock(part['end_time'])}] {str(part['summary']).strip()}" for part in parts if str(part.get("summary", "")).strip()]
    if events:
        lines.append("Story events:")
        lines += [f"[{clock(event['start_time'])}] {str(event['title']).strip()}: {str(event.get('description', '')).strip()}" for event in events]
    return "\n".join(lines)


def clock(seconds: Any) -> str:
    total = max(0, int(float(seconds)))
    return f"{total // 3600}:{total % 3600 // 60:02d}:{total % 60:02d}"


def parse_json_object(output: str) -> dict[str, Any]:
    text = output.strip()
    if text.startswith("```") and text.endswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text, flags=re.IGNORECASE).strip()
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ValueError("Het eventmodel gaf geen geldige JSON terug") from exc
    if not isinstance(parsed, dict):
        raise ValueError("Het eventmodel gaf geen JSON-object terug")
    return parsed


def parse_model_output(output: str) -> dict[str, Any]:
    parsed = parse_json_object(output)
    if not isinstance(parsed.get("events"), list):
        raise ValueError("De JSON van het eventmodel bevat geen events-lijst")
    return parsed


def parse_summary(payload: dict[str, Any]) -> str:
    summary = payload.get("summary")
    return summary.strip() if isinstance(summary, str) else ""


# An event is a happening, not a single remark, and covers it from start to end; a part of 5 minutes has ~150-250
# lines, so more than the max is a summary of the whole part.
MIN_SEGMENTS_PER_EVENT = int(os.getenv("EVENT_MIN_SEGMENTS", "2"))
MAX_SEGMENTS_PER_EVENT = int(os.getenv("EVENT_MAX_SEGMENTS", "200"))


def is_index(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def event_indexes(event: dict[str, Any]) -> Any:
    """The event's lines from first_line to last_line, which the prompt asks for: listing every index of a long
    happening made the output run out of tokens. A "lines" list (the model sometimes lists them anyway) counts as the
    range from its lowest to its highest line; a "segment_indexes" list is taken as is."""
    if "first_line" in event or "last_line" in event:
        first, last = event.get("first_line"), event.get("last_line")
        if not is_index(first) or not is_index(last) or first > last:
            raise ValueError("Event first_line and last_line must be line numbers, first <= last")
        return list(range(first, last + 1))
    lines = event.get("lines")
    if lines is None:
        return event.get("segment_indexes")
    if not isinstance(lines, list) or len(lines) < 2 or not all(is_index(line) for line in lines):
        raise ValueError("Event lines must be [first, last]")
    return list(range(min(lines), max(lines) + 1))


def validate_event(event: Any, segments: list[dict[str, Any]]) -> dict[str, Any]:
    if not isinstance(event, dict):
        raise ValueError("Event must be an object")
    event_type = event.get("type")
    title = str(event.get("title", "")).strip()
    description = str(event.get("description", "")).strip()
    indexes = event_indexes(event)
    confidence = event.get("confidence")
    if event_type not in EVENT_TYPES or not title or not description:
        raise ValueError("Event has an invalid type, title, or description")
    if not isinstance(indexes, list) or not indexes or any(
        isinstance(index, bool) or not isinstance(index, int) or index < 0 or index >= len(segments) for index in indexes
    ):
        raise ValueError("Event contains an invalid segment index")
    if isinstance(confidence, bool) or not isinstance(confidence, (int, float)) or not 0 <= confidence <= 1:
        raise ValueError("Event confidence must be between 0 and 1")
    indexes = sorted(set(indexes))
    said = " ".join(str(segments[index]["text"]) for index in indexes if 0 <= index < len(segments))
    # The quote is optional (older prompts), but a quote that is not in the lines means the event was made up.
    quote = event.get("quote")
    if isinstance(quote, str) and quote.strip() and not quote_found(quote, said):
        raise ValueError(f"Event quote is not in its lines: {quote[:80]}")
    made_up = ungrounded_terms(f"{title} {description}", " ".join(str(segment["text"]) for segment in segments))
    if made_up:
        raise ValueError(f"Event names {', '.join(made_up)}, which nobody says")
    if CHAT_PATTERN.search(title):
        raise ValueError("Event is about chat or the stream")
    if len(indexes) < MIN_SEGMENTS_PER_EVENT:
        raise ValueError(f"Event spans {len(indexes)} segment(s); fewer than {MIN_SEGMENTS_PER_EVENT} is a remark, not an event")
    # An event over (almost) all lines of a real part is the part's summary again; the model did that for most parts of a
    # long talk. Short parts (the end of a stream) are exempt. Same rule in ExtractStreamEventsJob::coversWholeChunk.
    if len(segments) >= 40 and len(indexes) > 0.8 * len(segments):
        raise ValueError(f"Event spans {len(indexes)} of the part's {len(segments)} lines: that is the part's summary")
    if len(indexes) > MAX_SEGMENTS_PER_EVENT:
        raise ValueError(f"Event spans {len(indexes)} segments; more than {MAX_SEGMENTS_PER_EVENT} is a chunk summary, not an event")
    # Timestamps always come from the referenced transcript segments, never from the model.
    return {
        "type": event_type,
        "title": title[:255],
        "description": description,
        "start_time": min(float(segments[index]["start_time"]) for index in indexes),
        "end_time": max(float(segments[index]["end_time"]) for index in indexes),
        "confidence": float(confidence),
        "segment_indexes": indexes,
    }


def validate_events(payload: dict[str, Any], segments: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Keeps the valid events; an invalid event is skipped and logged instead of rejecting the chunk."""
    validated: list[dict[str, Any]] = []
    for event in payload["events"]:
        try:
            validated.append(validate_event(event, segments))
        except ValueError as exc:
            print(f"event extractor: skipped invalid event ({exc}): {json.dumps(event)[:300]}", flush=True)
    return validated


class EventExtractor:
    def __init__(self, models: ModelManager) -> None:
        self.models = models
        self.model_name = os.getenv("EVENT_MODEL", "unsloth/Qwen3-8B-bnb-4bit")
        self.device = os.getenv("EVENT_DEVICE", "cuda")
        self.quantization = os.getenv("EVENT_QUANTIZATION", "4bit")
        self.max_tokens = int(os.getenv("EVENT_MAX_TOKENS", "1024"))
        self.story_max_tokens = int(os.getenv("EVENT_STORY_MAX_TOKENS", "600"))

    def _load(self) -> tuple[Any, Any]:
        if self.device == "mlx":
            # Apple Silicon (macOS, see scripts/worker-mac.sh): a pre-quantized MLX checkpoint on Metal.
            from mlx_lm import load

            return load(self.model_name)

        import torch
        from transformers import AutoConfig, AutoModelForCausalLM, AutoTokenizer, BitsAndBytesConfig

        if self.device == "cuda" and not torch.cuda.is_available():
            raise RuntimeError("EVENT_DEVICE=cuda maar CUDA is niet beschikbaar in de worker")
        use_cuda = self.device == "cuda"
        dtype = torch.bfloat16 if use_cuda and torch.cuda.is_bf16_supported() else (torch.float16 if use_cuda else torch.float32)
        kwargs: dict[str, Any] = {"device_map": "auto" if use_cuda else None, "dtype": dtype}
        # Pre-quantized checkpoints (e.g. *-bnb-4bit) carry their own quantization config.
        prequantized = getattr(AutoConfig.from_pretrained(self.model_name), "quantization_config", None) is not None
        if self.quantization == "4bit" and not prequantized:
            kwargs["quantization_config"] = BitsAndBytesConfig(
                load_in_4bit=True,
                bnb_4bit_quant_type="nf4",
                bnb_4bit_compute_dtype=dtype,
                bnb_4bit_use_double_quant=True,
            )
        tokenizer = AutoTokenizer.from_pretrained(self.model_name)
        model = AutoModelForCausalLM.from_pretrained(self.model_name, **kwargs)
        model.eval()
        return model, tokenizer

    def extract(self, segments: list[dict[str, Any]], context: dict[str, Any] | None = None) -> dict[str, Any]:
        """The story events of one part of a stream, and a short summary of what happens in the game in it."""
        if not segments:
            return {"events": [], "summary": ""}
        with self.models.use("event_llm", self._load) as (model, tokenizer):
            decoded = self._generate(model, tokenizer, segments, context)
        payload = parse_model_output(decoded)
        return {"events": validate_events(payload, segments), "summary": clean_text(parse_summary(payload), " ".join(str(segment["text"]) for segment in segments))}

    def summarize_story(self, parts: list[dict[str, Any]], events: list[dict[str, Any]], context: dict[str, Any] | None = None) -> dict[str, Any]:
        """The storyline of a whole stream from the summaries of its parts and its events."""
        if not any(str(part.get("summary", "")).strip() for part in parts) and not events:
            return {"summary": "", "players": []}
        with self.models.use("event_llm", self._load) as (model, tokenizer):
            return self.write_story(model, tokenizer, parts, events, context or {})

    def write_story(self, model: Any, tokenizer: Any, parts: list[dict[str, Any]], events: list[dict[str, Any]], context: dict[str, Any]) -> dict[str, Any]:
        """One paragraph per hour of the stream, cleaned (see clean_text), and the players in it."""
        paragraphs: list[str] = []
        names: list[Any] = []
        for chapter_parts, chapter_events in chapters(parts, events):
            try:
                payload = parse_json_object(self._complete(model, tokenizer, STORY_PROMPT, build_story_prompt(chapter_parts, chapter_events, context), self.story_max_tokens))
            except ValueError as exc:
                # One unusable hour costs only that paragraph.
                print(f"event extractor: skipped a story chapter ({exc})", flush=True)
                continue
            # Checked against what the model was given: a term in neither the summaries nor the events is made up.
            source = " ".join([*(str(part["summary"]) for part in chapter_parts), *(f"{event['title']} {event.get('description', '')}" for event in chapter_events)])
            paragraph = clean_text(parse_summary(payload), source)
            if paragraph:
                paragraphs.append(paragraph)
            if isinstance(payload.get("players"), list):
                names += payload["players"]
        # Repeats across chapters (the same happening at the end of one hour and the start of the next).
        return {"summary": clean_text("\n\n".join(paragraphs), " ".join(paragraphs)), "players": story_players(names, context)}

    def _generate(self, model: Any, tokenizer: Any, segments: list[dict[str, Any]], context: dict[str, Any] | None = None) -> str:
        return self._complete(model, tokenizer, SYSTEM_PROMPT, build_prompt(segments, context), self.max_tokens)

    def _complete(self, model: Any, tokenizer: Any, system: str, user: str, max_tokens: int) -> str:
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ]
        prompt = tokenizer.apply_chat_template(
            messages, tokenize=False, add_generation_prompt=True, enable_thinking=False
        )
        if self.device == "mlx":
            from mlx_lm import generate

            # Greedy by default, like do_sample=False below.
            return generate(model, tokenizer, prompt=prompt, max_tokens=max_tokens)

        import torch

        inputs = tokenizer(prompt, return_tensors="pt")
        device = next(model.parameters()).device
        inputs = {key: value.to(device) for key, value in inputs.items()}
        with torch.inference_mode():
            output = model.generate(
                **inputs,
                max_new_tokens=max_tokens,
                do_sample=False,
                pad_token_id=tokenizer.eos_token_id,
            )
        generated = output[0][inputs["input_ids"].shape[1]:]
        return tokenizer.decode(generated, skip_special_tokens=True)

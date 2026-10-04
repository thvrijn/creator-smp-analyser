import json
import os
import re
from typing import Any

from model_manager import ModelManager

EVENT_TYPES = {
    "player_encounter",
    "combat",
    "death",
    "discovery",
    "item",
    "building",
    "destruction",
    "conversation",
    "statement",
    "other",
}

# Tuned with a prompt eval on synthetic Dutch SMP chunks and a real stream; see TODO.md for the results.
SYSTEM_PROMPT = """You find notable moments in one chunk of a Minecraft SMP livestream transcript. Around 90 players share one server and each streams their own perspective; they regularly run into each other. The speech is usually Dutch, sometimes English, and comes from automatic speech recognition, so expect odd wording, misspelled names and transcription errors.

An event is one specific moment a viewer would want to find back or clip, for example: meeting or talking with another player, a fight, a death, a funny or dramatic moment, a discovery, getting or losing an important item, finishing or showing a build, something being destroyed or griefed, a plan, announcement or strong opinion about the server or its players.

Rules:
- List every separate moment in the chunk as its own event. Never summarise the chunk as one event.
- An event uses only the lines where it happens: usually 1 to 6 lines, never more than 10. Leave out lead-up and unrelated lines.
- Always name the other players involved, exactly as spoken, in the title and description. Never invent players, items, places or outcomes.
- Ignore filler, it is never an event: greetings, reading, thanking or answering chat and followers, donations, audio or camera checks, breaks ("even water drinken", "ben zo terug"), food, small talk, complaining while playing.
- A chunk with only filler returns {"events": []}.
- If a moment is plausibly notable but vague, include it with a lower confidence (0.4-0.6).
- title: max 8 words, English, specific: who and what (e.g. "Meets Sam at the bastion", not "Exploring").
- description: 1-2 English sentences.
- type: only when it clearly fits, otherwise "other". Clear types:
  player_encounter (meets or spots another named player), conversation (meaningful talk with another player), combat (a fight), death (someone dies), discovery (finds a place, structure or valuable resource), item (gets, crafts, trades or loses an important item), building (starts, finishes or shows a build), destruction (something destroyed, burned or griefed), statement (announcement, plan, opinion or story about the server or its players).
- confidence: 0.9+ when explicit and clear, 0.6-0.8 when likely, 0.4-0.6 when vague.

Example input:
0: [10.000] Oké, even naar de nether.
1: [14.000] Kijk, daar is een bastion!
2: [18.000] Daar staat Sam, die vecht met piglins.
3: [22.000] Sam, ik kom je helpen!
4: [26.000] We hebben ze allemaal verslagen.
5: [30.000] Even kijken in de chat.
Example output:
{"events":[{"type":"discovery","title":"Finds a bastion in the Nether","description":"The streamer spots a bastion while exploring the Nether.","confidence":0.85,"segment_indexes":[1]},{"type":"combat","title":"Helps Sam fight piglins at the bastion","description":"The streamer joins Sam, who is fighting piglins, and together they defeat them.","confidence":0.9,"segment_indexes":[2,3,4]}]}

Return only JSON with exactly these keys, nothing else:
{"events":[{"type":"...","title":"...","description":"...","confidence":0.0,"segment_indexes":[0]}]}
"""


def build_prompt(segments: list[dict[str, Any]]) -> str:
    lines = [
        f"[{float(segment['start_time']):.3f}] {segment['text']}"
        for segment in segments
    ]
    return "Transcript segment indexes and timestamps:\n" + "\n".join(
        f"{index}: {line}" for index, line in enumerate(lines)
    )


def parse_model_output(output: str) -> dict[str, Any]:
    text = output.strip()
    if text.startswith("```") and text.endswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text, flags=re.IGNORECASE).strip()
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ValueError("Event model returned invalid JSON") from exc
    if not isinstance(parsed, dict) or not isinstance(parsed.get("events"), list):
        raise ValueError("Event model JSON must contain an events array")
    return parsed


MAX_SEGMENTS_PER_EVENT = int(os.getenv("EVENT_MAX_SEGMENTS", "12"))


def validate_event(event: Any, segments: list[dict[str, Any]]) -> dict[str, Any]:
    if not isinstance(event, dict):
        raise ValueError("Event must be an object")
    event_type = event.get("type")
    title = str(event.get("title", "")).strip()
    description = str(event.get("description", "")).strip()
    indexes = event.get("segment_indexes")
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
        self.max_tokens = int(os.getenv("EVENT_MAX_TOKENS", "768"))

    def _load(self) -> tuple[Any, Any]:
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

    def extract(self, segments: list[dict[str, Any]]) -> list[dict[str, Any]]:
        if not segments:
            return []
        with self.models.use("event_llm", self._load) as (model, tokenizer):
            decoded = self._generate(model, tokenizer, segments)
        return validate_events(parse_model_output(decoded), segments)

    def _generate(self, model: Any, tokenizer: Any, segments: list[dict[str, Any]]) -> str:
        import torch

        messages = [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": build_prompt(segments)},
        ]
        prompt = tokenizer.apply_chat_template(
            messages, tokenize=False, add_generation_prompt=True, enable_thinking=False
        )
        inputs = tokenizer(prompt, return_tensors="pt")
        device = next(model.parameters()).device
        inputs = {key: value.to(device) for key, value in inputs.items()}
        with torch.inference_mode():
            output = model.generate(
                **inputs,
                max_new_tokens=self.max_tokens,
                do_sample=False,
                pad_token_id=tokenizer.eos_token_id,
            )
        generated = output[0][inputs["input_ids"].shape[1]:]
        return tokenizer.decode(generated, skip_special_tokens=True)

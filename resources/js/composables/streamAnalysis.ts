import type { Stream } from '../types/streams';

// The Analysis tab: the stream as moments. Events are the happenings the analysis found, at their real time; the
// stretches between them are quiet (gameplay, chat: summarised by the analysis' storyline parts when it wrote one) or
// silent (no speech: another game, outside the transcribed ranges, or not transcribed yet). Relevance of an event is
// example data until the analysis gives it.

export type Category = 'gameplay' | 'chat' | 'social' | 'strategy' | 'conflict' | 'other';
export const categories: Category[] = ['gameplay', 'chat', 'social', 'strategy', 'conflict', 'other'];
export const categoryLabels: Record<Category, string> = { gameplay: 'Gameplay', chat: 'Chat', social: 'Sociaal', strategy: 'Plannen / strategie', conflict: 'Conflict', other: 'Overig' };
export const categoryShort: Record<Category, string> = { gameplay: 'gameplay', chat: 'chat', social: 'sociaal', strategy: 'strategie', conflict: 'conflict', other: 'overig' };
export const categoryWeight: Record<Category, 'laag' | 'gemiddeld' | 'hoog' | null> = { gameplay: 'laag', chat: 'laag', social: 'gemiddeld', strategy: 'hoog', conflict: 'hoog', other: null };

// Event types (the worker's EVENT_TYPES) grouped into the timeline's categories.
const typeCategory: Record<string, Category> = {
    conflict: 'conflict', combat: 'conflict', death: 'conflict', destruction: 'conflict',
    plot: 'strategy', trade: 'strategy', teamwork: 'strategy',
    player_encounter: 'social', conversation: 'social',
    discovery: 'gameplay', building: 'gameplay', item: 'gameplay',
    statement: 'chat',
};
export const eventCategory = (type: string): Category => typeCategory[type] ?? 'other';

/** The same "random" number for the same input, so example data does not change between page loads. */
export const seeded = (...parts: (number | string)[]) => {
    let hash = 2166136261;
    for (const char of parts.join('|')) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619);
    return ((hash >>> 0) % 10000) / 10000;
};

type MomentEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; speakers: Record<string, number> };
type StoryPart = { start_time: number; end_time: number; summary: string };
export type SpeechMinute = { lines: number; speakers: Record<string, number> };

export type Moment = {
    key: string;
    kind: 'event' | 'quiet' | 'silent';
    start: number;
    end: number;
    event: MomentEvent | null;
    category: Category | null;
    // Events only; example data.
    relevance: number | null;
    // Quiet stretches: what the analysis wrote about that time.
    summaries: string[];
    lines: number;
};

// Gaps between events shorter than this are not shown; silences shorter than SILENT_MIN are part of a quiet stretch.
const GAP_MIN = 60;
const SILENT_MIN = 180;
const RUN_MIN = 120;

/** Example relevance of an event: conflict and plans matter most, routine least; confident and long events a bit more. */
export const eventRelevance = (event: Pick<MomentEvent, 'type' | 'confidence' | 'start_time' | 'end_time'>) => {
    const base = { conflict: 72, strategy: 64, social: 52, gameplay: 30, chat: 20, other: 40 }[eventCategory(event.type)];
    return Math.round(Math.min(98, base + event.confidence * 15 + Math.min(10, (event.end_time - event.start_time) / 60)));
};

const speechIn = (minutes: Record<string, SpeechMinute>, start: number, end: number) => {
    let lines = 0;
    for (let minute = Math.floor(start / 60); minute * 60 < end; minute++) lines += minutes[String(minute)]?.lines ?? 0;
    return lines;
};

/** A gap between events, split into quiet (speech) and silent (no speech) stretches by the minute. */
const gapMoments = (start: number, end: number, minutes: Record<string, SpeechMinute>, parts: StoryPart[]): Moment[] => {
    type Run = { silent: boolean; start: number; end: number };
    const runs: Run[] = [];
    for (let at = start; at < end;) {
        const next = Math.min(end, (Math.floor(at / 60) + 1) * 60);
        const silent = (minutes[String(Math.floor(at / 60))]?.lines ?? 0) === 0;
        const last = runs.at(-1);
        if (last && last.silent === silent) last.end = next; else runs.push({ silent, start: at, end: next });
        at = next;
    }
    // Short silences (a pause, a cut) belong to the quiet stretch around them.
    const merged: Run[] = [];
    for (const run of runs) {
        const silent = run.silent && run.end - run.start >= SILENT_MIN;
        const last = merged.at(-1);
        if (last && last.silent === silent) last.end = run.end; else merged.push({ ...run, silent });
    }
    // A scrap shorter than RUN_MIN (a line before a break, a short pause) joins its neighbour; the joined stretch is
    // quiet or silent after whichever of the two is longer.
    const length = (run: Run) => run.end - run.start;
    const tidy: Run[] = [];
    for (const run of merged) {
        const last = tidy.at(-1);
        if (last && (length(run) < RUN_MIN || length(last) < RUN_MIN)) {
            last.silent = length(run) > length(last) ? run.silent : last.silent;
            last.end = run.end;
        } else tidy.push({ ...run });
    }
    return tidy.map((run) => ({
        key: (run.silent ? 'silent-' : 'quiet-') + run.start,
        kind: run.silent ? 'silent' : 'quiet',
        start: run.start, end: run.end, event: null, category: null, relevance: null,
        summaries: run.silent ? [] : parts.filter((part) => (part.start_time + part.end_time) / 2 >= run.start && (part.start_time + part.end_time) / 2 < run.end).map((part) => part.summary),
        lines: speechIn(minutes, run.start, run.end),
    }));
};

/** The whole file, from 0 to its end, as events and the stretches between them. */
export const buildMoments = (options: { events: MomentEvent[]; length: number; minutes: Record<string, SpeechMinute>; parts: StoryPart[] }): Moment[] => {
    const { minutes, parts } = options;
    const events = [...options.events].sort((a, b) => a.start_time - b.start_time || a.id - b.id);
    const lastMinute = Math.max(-1, ...Object.keys(minutes).map(Number));
    const length = Math.max(options.length, (lastMinute + 1) * 60, ...events.map((event) => event.end_time));
    const moments: Moment[] = [];
    let cursor = 0;
    const gap = (until: number) => {
        if (until - cursor >= GAP_MIN) moments.push(...gapMoments(cursor, until, minutes, parts));
    };
    for (const event of events) {
        gap(event.start_time);
        moments.push({
            key: 'event-' + event.id, kind: 'event', start: event.start_time, end: event.end_time, event,
            category: eventCategory(event.type), relevance: eventRelevance(event), summaries: [], lines: speechIn(minutes, event.start_time, event.end_time),
        });
        // Overlapping events: the gap starts after the one that ends last.
        cursor = Math.max(cursor, event.end_time);
    }
    gap(length);
    return moments;
};

/** Who speaks between two moments in the file: speaker number => lines, most first. */
export const speakersBetween = (minutes: Record<string, SpeechMinute>, start: number, end: number) => {
    const counts = new Map<number, number>();
    for (let minute = Math.floor(start / 60); minute * 60 < end; minute++) {
        for (const [speaker, lines] of Object.entries(minutes[String(minute)]?.speakers ?? {})) counts.set(Number(speaker), (counts.get(Number(speaker)) ?? 0) + lines);
    }
    return [...counts.entries()].sort((a, b) => b[1] - a[1]).map(([speaker, lines]) => ({ speaker, lines }));
};

// The analysis' own parts (5 minutes, overlapping; ExtractStreamEventsJob::chunks) for the details view.
export type PartStatus = 'done' | 'analyzing' | 'queued' | 'waiting';
export const partStatusLabels: Record<PartStatus, string> = { done: 'Klaar', analyzing: 'Analyseren', queued: 'In wachtrij', waiting: 'Niet geanalyseerd' };
export const partStatus = (stream: Pick<Stream, 'event_extraction_status' | 'event_extraction_chunks_done' | 'event_extraction_stalled'>, index: number): PartStatus => {
    if (stream.event_extraction_status === 'completed') return 'done';
    if (['queued', 'waiting', 'processing'].includes(stream.event_extraction_status) && !stream.event_extraction_stalled) {
        if (stream.event_extraction_status !== 'processing') return 'queued';
        return index < stream.event_extraction_chunks_done ? 'done' : index === stream.event_extraction_chunks_done ? 'analyzing' : 'queued';
    }
    return 'waiting';
};

/** "12 min", "1u 05m". */
export const durationLabel = (seconds: number) => {
    const minutes = Math.max(1, Math.round(seconds / 60));
    return minutes >= 60 ? Math.floor(minutes / 60) + 'u ' + String(minutes % 60).padStart(2, '0') + 'm' : minutes + ' min';
};

export const relevanceLevel = (relevance: number) => relevance >= 70 ? 'high' : relevance >= 40 ? 'medium' : 'low';

/** "3 dagen geleden", "gisteren", "vandaag". */
export const daysAgo = (value: string) => {
    const days = Math.floor((Date.now() - Date.parse(value)) / 86400000);
    return days <= 0 ? 'vandaag' : days === 1 ? 'gisteren' : days + ' dagen geleden';
};

// Example context until the analysis keeps track of goals, open questions and relations between players.
const goalPool = ['Base uitbreiden', 'Bondgenootschappen versterken', 'Mogelijke aanval plannen', 'Grondstoffen verzamelen voor de wijk', 'Een handelsroute opzetten', 'Wraak nemen voor de laatste overval'];
const questionPool = ['Gaat het aanvalsplan daadwerkelijk door?', 'Weet de ander van het wantrouwen?', 'Wie zat er achter de diefstal?', 'Blijft de alliantie na deze ruzie staan?'];
export type Relation = 'ally' | 'rival' | 'neutral';
export const relationLabels: Record<Relation, string> = { ally: 'Bondgenoot', rival: 'Rivaal', neutral: 'Neutraal' };
export const demoGoals = (playerId: number) => [0, 1, 2].map((offset) => goalPool[(Math.floor(seeded(playerId, 'g') * goalPool.length) + offset * 2) % goalPool.length]);
export const demoQuestions = (playerId: number) => [0, 1].map((offset) => questionPool[(Math.floor(seeded(playerId, 'q') * questionPool.length) + offset) % questionPool.length]);
export const demoRelation = (a: number, b: number): Relation => {
    const value = seeded(Math.min(a, b), Math.max(a, b), 'rel');
    return value < 0.45 ? 'ally' : value < 0.75 ? 'rival' : 'neutral';
};

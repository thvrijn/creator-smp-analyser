import type { Stream } from './streams';

// The props of the stream page (StreamController@show).
// story_*: the storyline of the stream written by the analysis, and a summary per part (file times).
export type StoryPart = { start_time: number; end_time: number; summary: string };
export type StreamDetail = Stream & { duration_seconds: number | null; segment_count: number; story_summary: string | null; story_players: string[]; story_parts: StoryPart[] };
// speakers: who speaks in the event, speaker number => lines.
export type StreamEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number; speakers: Record<string, number> };
// team: Noord or Zuid (CreatorSmpTeamSeeder), null when unknown.
export type PlayerItem = { id: number; name: string; photo_url: string | null; team?: string | null };
// The same moment in another player's stream (SharedMoments): their event at that time, or else the time in their file.
export type SharedMoment = { stream_id: number; stream_title: string; player: PlayerItem; event_id: number | null; event_title: string | null; at: number; reasons: ('voice' | 'named' | 'voice_there' | 'named_there')[] };
// What the stream's player did in their other streams lately (the context panel).
export type PlayerEvent = { id: number; stream_id: number; type: string; title: string; at: string };

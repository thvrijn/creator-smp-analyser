// A diarized speaker of a stream (StreamSpeakers::list). source: named/unknown by hand, matched (by text: the same
// sentences as that player in their own stream, see SpeakerTextMatches; or by voice, VoiceProfiles, with its
// similarity), or default (0 = the streamer).
export type Speaker = { speaker: number; name: string; source: 'named' | 'unknown' | 'matched' | 'default'; named: boolean; player_id: number | null; label: string | null; matched_by: 'text' | 'voice' | null; similarity: number | null; text_hits: number | null; text_stream_id: number | null; seconds: number; segment_count: number };

export const similarityLabel = (item: Speaker) => Math.round((item.similarity ?? 0) * 100) + '% gelijk';
// Why a speaker is shown as a player: what they say, or how they sound.
export const matchReason = (item: Speaker) => item.matched_by === 'text'
    ? 'zegt ' + item.text_hits + ' keer hetzelfde als ' + item.name + ' op dat moment in diens eigen stream'
    : 'stem ' + similarityLabel(item);

// "Spreker 6" in a generated text (event, storyline): the analysis names unknown voices by their number.
export const SPEAKER_MENTION = /\b[Ss]preker (\d+)\b/g;

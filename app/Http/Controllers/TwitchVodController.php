<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVodJob;
use App\Models\Player;
use App\Models\Stream;
use App\Services\TwitchVodSync;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use RuntimeException;

class TwitchVodController extends Controller
{
    public function player(Player $player, TwitchVodSync $sync): RedirectResponse
    {
        return $this->sync($sync, collect([$player]));
    }

    public function all(TwitchVodSync $sync): RedirectResponse
    {
        return $this->sync($sync, Player::query()->whereNotNull('twitch_login')->get());
    }

    public function download(Stream $stream): RedirectResponse
    {
        $back = back(fallback: route('streams.index'));
        if ($stream->twitch_video_id === null) {
            return $back->with('error', 'Deze stream is geen Twitch-VOD.');
        }
        if ($stream->ended_at === null) {
            return $back->with('error', "Deze stream is nog live. Haal de audio op als hij voorbij is, en sync de VOD's dan eerst opnieuw.");
        }
        if ($stream->video_path !== null) {
            return $back->with('error', 'Deze stream heeft al audio of video.');
        }
        if (in_array($stream->video_download_status, ['queued', 'processing'], true) && ! $stream->isStalled('video_download_status')) {
            return $back->with('error', 'De audio wordt al opgehaald.');
        }

        $stream->forceFill(['video_download_status' => 'queued', 'video_download_progress' => 0, 'video_download_error' => null])->save();
        DownloadVodJob::dispatch($stream->id);

        return $back->with('success', 'De audio wordt opgehaald. Daarna kun je transcriberen.');
    }

    /** @param  Collection<int, Player>  $players */
    private function sync(TwitchVodSync $sync, Collection $players): RedirectResponse
    {
        try {
            ['new' => $new, 'failed' => $failed] = $sync->sync($players);
        } catch (HttpClientException) {
            return back(fallback: route('dashboard'))->with('error', 'Twitch is niet bereikbaar of gaf een fout. Probeer het later opnieuw.');
        } catch (RuntimeException $exception) {
            return back(fallback: route('dashboard'))->with('error', $exception->getMessage());
        }

        $message = match ($new) {
            0 => "Geen nieuwe VOD's.",
            1 => '1 nieuwe VOD toegevoegd.',
            default => "{$new} nieuwe VOD's toegevoegd.",
        };
        if ($failed !== []) {
            $message .= ' Niet gelukt voor: '.implode(', ', $failed).'.';
        }

        return back(fallback: route('dashboard'))->with($failed === [] ? 'success' : 'error', $message);
    }
}

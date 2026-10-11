<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Stream;
use App\Services\StreamPipeline;
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

    public function download(Stream $stream, StreamPipeline $pipeline): RedirectResponse
    {
        $error = $pipeline->download($stream);

        return back(fallback: route('streams.index'))->with($error === null ? 'success' : 'error', $error ?? 'De audio wordt opgehaald. Daarna kun je transcriberen.');
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

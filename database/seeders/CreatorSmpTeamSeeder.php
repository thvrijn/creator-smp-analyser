<?php

namespace Database\Seeders;

use App\Models\Player;
use Illuminate\Database\Seeder;

/**
 * The team (Noord or Zuid, on either side of the wall) of every Creator SMP 4 player, as creatorsmp.nl listed them on
 * 11 October 2026 (fetched once; the teams do not change during the season). Run after CreatorSmpPlayerSeeder.
 * Safe to re-run: only players without a team get one, so a team changed in the app is kept.
 */
class CreatorSmpTeamSeeder extends Seeder
{
    private const NOORD = [
        'Acid', 'AkaLuuk', 'APPELKAAS', 'Bokado', 'Brolsen', 'ClownPierce', 'Dante', 'Deem', 'DionTVG', 'DonKaaklijn',
        'egbertlive', 'Ezra', 'Faynominaal', 'Fems', 'GeorgeFaralley', 'Gio Latooy', 'HARM', 'Igortjuh', 'Imkefleur',
        'Izza', 'jactha', 'Javieros', 'Jelly', 'Jeremy Frieser', 'JPAIR', 'Laagvliet', 'LinkTijger', 'Ljoeb',
        'MaicoBeukers', 'MarnixWesthuis', 'MaysieLive', 'Merretje', 'Mokka', 'Morrog', 'NatVlees', 'NoaNoella',
        'Noël Dekkers', 'papaneus', 'Paraduze', 'PuqLive', 'Puxque', 'Qucee', 'QuintenVms', 'Roedie', 'Yarasky',
    ];

    private const ZUID = [
        'Alex Klein', 'alissarella', 'AltijdMelvin', 'AwesomeDad', 'AYV3E', 'BardoEllens', 'Bassistentje', 'BellieTV',
        'Bintjeeeh', 'Chatmo', 'DavincstyleGames', 'Dennus', 'Duncan', 'eenhoorntamara', 'Enzo Knol', 'GameMeneer',
        'henRYANand', 'Idris', 'ikbenwela', 'Its_Ankie', 'Job Dutchtuber', 'kantje', 'KingKontent', 'Loony', 'Lotje',
        'Manon', 'Mick', 'NoVertigo', 'OmaScheetReet', 'Pangi', 'Pascal', 'Puddingb00m', 'Ronald', 'Royalistiq',
        'SanDramatis', 'Santino_YT', 'sejecem', 'Shandrea', 'Shappo0', 'SlushieVRC', 'spacingunicorn',
        'StreamwithLien', 'Vince_STICK', 'Weswoes', 'Wolfeei',
    ];

    public function run(): void
    {
        foreach (['Noord' => self::NOORD, 'Zuid' => self::ZUID] as $team => $names) {
            Player::query()->whereIn('name', $names)->whereNull('team')->update(['team' => $team]);
        }
    }
}

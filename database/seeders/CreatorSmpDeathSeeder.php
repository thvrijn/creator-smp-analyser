<?php

namespace Database\Seeders;

use App\Models\Player;
use App\Models\PlayerDeath;
use Illuminate\Database\Seeder;

/**
 * The deaths on the Creator SMP 4 server as creatorsmp.nl listed them on 11 October 2026 (fetched once): when, and the
 * game's death message. A death in a player's first hour of play (the grace period) is not final; this list does not
 * say which ones were. Run after CreatorSmpPlayerSeeder. Safe to re-run: each death is stored once (by its id there).
 */
class CreatorSmpDeathSeeder extends Seeder
{
    /** [id on creatorsmp.nl, player name, time (UTC), death message] */
    private const DEATHS = [
        ['7d4088e1-5aee-4e6e-ae04-2b35464e036f', 'MarnixWesthuis', '2026-10-04T20:59:45Z', 'MarnixWesthuis was slain by Zombie'],
        ['d9e86bf7-556a-4c2e-b9ce-ea9a085d02fd', 'Ljoeb', '2026-10-04T21:00:10Z', 'Ljoeb was slain by Zombie'],
        ['ee4f8315-86a3-4d5d-986d-3c18fccfb060', 'Izza', '2026-10-05T12:18:14Z', 'x_izza_x was impaled on a stalagmite'],
        ['099a543d-3aac-475c-a3c9-91a91178891a', 'QuintenVms', '2026-10-05T13:09:43Z', 'QuintenVms was blown up by Creeper'],
        ['fba8916f-4ad2-4ae4-aa33-d85777d56bbe', 'jactha', '2026-10-05T16:13:32Z', 'jactha was slain by Enderman'],
        ['0573fd92-c5a1-42c9-9056-a11311e58c06', 'ikbenwela', '2026-10-05T19:51:38Z', 'ikbenwela was doomed to fall by Pangi'],
        ['50d36803-c465-4947-93cd-67c518426caa', 'Ezra', '2026-10-06T12:22:26Z', 'Ezrrrrra was blown up by Creeper'],
        ['ca906f2a-ed1e-4d2e-9df1-37f9384a8dea', 'BellieTV', '2026-10-06T14:50:49Z', 'BellieTV fell from a high place'],
        ['20d478f8-aabb-40aa-92bc-efb4476ee2c4', 'henRYANand', '2026-10-06T19:59:09Z', '???'],
        ['24493257-fa5e-48f4-b5d8-e9f4d0afbcf4', 'APPELKAAS', '2026-10-09T19:33:01Z', 'Appelkaas was slain by Zombie'],
    ];

    public function run(): void
    {
        $players = Player::query()->whereIn('name', array_column(self::DEATHS, 1))->pluck('id', 'name');
        foreach (self::DEATHS as [$sourceId, $name, $diedAt, $message]) {
            if (! isset($players[$name])) {
                $this->command?->warn("No player {$name}: run CreatorSmpPlayerSeeder first.");

                continue;
            }
            PlayerDeath::updateOrCreate(['source_id' => $sourceId], ['player_id' => $players[$name], 'died_at' => $diedAt, 'message' => $message]);
        }
    }
}

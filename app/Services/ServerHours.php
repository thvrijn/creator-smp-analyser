<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The Creator SMP server's daily opening hours (normally 14:00-00:00 Dutch time). Outside them nobody plays,
 * so a VOD is only synced and kept for those hours.
 */
class ServerHours
{
    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> the opening hours that overlap [$from, $to], clipped to it, in UTC */
    public static function within(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('services.twitch.server_timezone');
        $windows = [];
        // From the day before: with closing hours after midnight, yesterday's window reaches into today.
        for ($day = $from->setTimezone($timezone)->subDay()->startOfDay(); $day->lt($to); $day = $day->addDay()) {
            // setTime(24, 0) rolls over to the next midnight.
            $start = max($day->setTime(config('services.twitch.server_opens_hour'), 0), $from);
            $end = min($day->setTime(config('services.twitch.server_closes_hour'), 0), $to);
            if ($start->lt($end)) {
                $windows[] = [$start->utc(), $end->utc()];
            }
        }

        return $windows;
    }
}

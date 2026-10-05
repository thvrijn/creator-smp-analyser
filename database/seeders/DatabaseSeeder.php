<?php

namespace Database\Seeders;

use App\Models\Player;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CreatorSmpPlayerSeeder::class);

        foreach (['Sophie', 'Lars', 'Mark'] as $name) {
            Player::firstOrCreate(['name' => $name]);
        }

        $sophie = Player::where('name', 'Sophie')->firstOrFail();
        $lars = Player::where('name', 'Lars')->firstOrFail();

        $sophie->streams()->firstOrCreate(
            ['title' => 'CreatorSMP avondstream'],
            [
                'started_at' => now()->subHour(),
                'source' => 'development',
            ],
        );

        $lars->streams()->firstOrCreate(
            ['title' => 'CreatorSMP bouwstream'],
            [
                'started_at' => now()->subHours(2),
                'ended_at' => now()->subHour(),
                'source' => 'development',
            ],
        );

        $sophieStream = $sophie->streams()->where('title', 'CreatorSMP avondstream')->firstOrFail();

        $sophieStream->transcriptSegments()->firstOrCreate(
            [
                'start_time' => '872.420',
                'end_time' => '876.810',
                'text' => 'Waar is Lars?',
            ],
        );

        $sophieStream->transcriptSegments()->firstOrCreate(
            [
                'start_time' => '1122.100',
                'end_time' => '1125.500',
                'text' => 'Oh Lars, daar ben je!',
            ],
        );
    }
}

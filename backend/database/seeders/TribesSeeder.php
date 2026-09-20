<?php

namespace Database\Seeders;

use App\Models\Tribe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TribesSeeder extends Seeder
{
    /** Les 12 tribus. */
    public const TRIBES = [
        'Ruben', 'Simeon', 'Levi', 'Juda', 'Dan', 'Nephtali',
        'Gad', 'Aser', 'Issacar', 'Zabulon', 'Joseph', 'Benjamin',
    ];

    public function run(): void
    {
        foreach (self::TRIBES as $name) {
            Tribe::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}

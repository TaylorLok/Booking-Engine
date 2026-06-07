<?php

namespace Database\Seeders;

use App\Models\RoomType;
use Database\Seeders\Concerns\ReadsPropertyJson;
use Illuminate\Database\Seeder;

class RoomTypeSeeder extends Seeder
{
    use ReadsPropertyJson;

    public function run(): void
    {
        $roomTypes = $this->propertyData()['room_types'] ?? [];

        foreach ($roomTypes as $index => $roomType) {
            if (! isset($roomType['slug'], $roomType['name'])) {
                continue;
            }

            RoomType::query()->updateOrCreate(
                ['slug' => $roomType['slug']],
                [
                    'name' => $roomType['name'],
                    'description' => $roomType['description'] ?? null,
                    'icon_slug' => $roomType['icon_slug'] ?? null,
                    'is_active' => $roomType['is_active'] ?? true,
                    'sort_order' => $roomType['sort_order'] ?? ($index + 1),
                ],
            );
        }
    }
}

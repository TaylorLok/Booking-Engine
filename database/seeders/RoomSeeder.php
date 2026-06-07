<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use Database\Seeders\Concerns\ReadsPropertyJson;
use Illuminate\Database\Seeder;
use RuntimeException;

class RoomSeeder extends Seeder
{
    use ReadsPropertyJson;

    public function run(): void
    {
        $rooms = $this->propertyData()['rooms'] ?? [];

        foreach ($rooms as $room) {
            if (! isset($room['slug'], $room['name'], $room['price_per_night_cents'])) {
                continue;
            }

            $roomTypeId = $this->resolveRoomTypeId($room);

            Room::query()->updateOrCreate(
                ['slug' => $room['slug']],
                [
                    'name' => $room['name'],
                    'room_type_id' => $roomTypeId,
                    'max_adults' => $room['max_adults'] ?? 2,
                    'max_children' => $room['max_children'] ?? 0,
                    'price_per_night_cents' => $room['price_per_night_cents'],
                    'is_active' => $room['is_active'] ?? true,
                    'total_units' => $room['total_units'] ?? 1,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function resolveRoomTypeId(array $room): int
    {
        if (isset($room['room_type_id'])) {
            return (int) $room['room_type_id'];
        }

        $slug = $room['room_type_slug'] ?? $room['type'] ?? null;

        if ($slug === null) {
            throw new RuntimeException("Room [{$room['slug']}] is missing room_type_slug.");
        }

        $roomType = RoomType::query()->where('slug', $slug)->first();

        if ($roomType === null) {
            throw new RuntimeException("Room type [{$slug}] not found for room [{$room['slug']}]. Run RoomTypeSeeder first.");
        }

        return $roomType->id;
    }
}

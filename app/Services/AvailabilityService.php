<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomHold;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    public function getAvailableUnits(Room $room, Carbon|string $checkIn, Carbon|string $checkOut): int
    {
        $confirmedCount = BookingRoom::query()
            ->where('room_id', $room->id)
            ->whereDateRangeOverlaps($checkIn, $checkOut)
            ->whereHas('booking', function ($query): void {
                $query->where('status', BookingStatus::Confirmed);
            })
            ->count();

        $activeHolds = RoomHold::query()
            ->where('room_id', $room->id)
            ->where('expires_at', '>', now())
            ->whereDateRangeOverlaps($checkIn, $checkOut)
            ->count();

        return max(0, $room->total_units - $confirmedCount - $activeHolds);
    }

    /**
     * @param  list<array{room_id: int, adults: int, children?: int}>  $roomSelections
     * @return array{available: bool, unavailable_room_ids: list<int>}
     */
    public function checkRooms(array $roomSelections, Carbon|string $checkIn, Carbon|string $checkOut): array
    {
        $unavailableRoomIds = [];

        foreach ($roomSelections as $selection) {
            $room = Room::query()
                ->where('id', $selection['room_id'])
                ->where('is_active', true)
                ->first();

            if ($room === null || $this->getAvailableUnits($room, $checkIn, $checkOut) < 1) {
                $unavailableRoomIds[] = (int) $selection['room_id'];
            }
        }

        return [
            'available' => $unavailableRoomIds === [],
            'unavailable_room_ids' => $unavailableRoomIds,
        ];
    }
}

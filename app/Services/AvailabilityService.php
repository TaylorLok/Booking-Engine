<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomHold;
use App\Support\BookingFlowLogger;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    public function __construct(
        private readonly BookingFlowLogger $logger,
    ) {}

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

        $available = max(0, $room->total_units - $confirmedCount - $activeHolds);

        $this->logger->debug('availability.units_calculated', [
            ...$this->logger->room($room),
            'check_in' => Carbon::parse($checkIn)->toDateString(),
            'check_out' => Carbon::parse($checkOut)->toDateString(),
            'confirmed_count' => $confirmedCount,
            'active_holds' => $activeHolds,
            'available_units' => $available,
        ]);

        return $available;
    }

    /**
     * @param  list<array{room_id: int, adults: int, children?: int}>  $roomSelections
     * @return array{available: bool, unavailable_room_ids: list<int>}
     */
    public function checkRooms(array $roomSelections, Carbon|string $checkIn, Carbon|string $checkOut): array
    {
        $this->logger->info('availability.check_started', [
            'check_in' => Carbon::parse($checkIn)->toDateString(),
            'check_out' => Carbon::parse($checkOut)->toDateString(),
            'room_count' => count($roomSelections),
            'room_ids' => collect($roomSelections)->pluck('room_id')->all(),
        ]);

        $unavailableRoomIds = [];

        foreach ($roomSelections as $selection) {
            $room = Room::query()
                ->where('id', $selection['room_id'])
                ->where('is_active', true)
                ->first();

            if ($room === null) {
                $unavailableRoomIds[] = (int) $selection['room_id'];

                $this->logger->warning('availability.room_not_found_or_inactive', [
                    'room_id' => $selection['room_id'],
                    'check_in' => Carbon::parse($checkIn)->toDateString(),
                    'check_out' => Carbon::parse($checkOut)->toDateString(),
                ]);

                continue;
            }

            $availableUnits = $this->getAvailableUnits($room, $checkIn, $checkOut);

            if ($availableUnits < 1) {
                $unavailableRoomIds[] = (int) $selection['room_id'];

                $this->logger->warning('availability.room_unavailable', [
                    ...$this->logger->room($room),
                    'check_in' => Carbon::parse($checkIn)->toDateString(),
                    'check_out' => Carbon::parse($checkOut)->toDateString(),
                    'available_units' => $availableUnits,
                ]);
            }
        }

        $result = [
            'available' => $unavailableRoomIds === [],
            'unavailable_room_ids' => $unavailableRoomIds,
        ];

        $this->logger->info('availability.check_completed', [
            'check_in' => Carbon::parse($checkIn)->toDateString(),
            'check_out' => Carbon::parse($checkOut)->toDateString(),
            'available' => $result['available'],
            'unavailable_room_ids' => $unavailableRoomIds,
        ]);

        return $result;
    }
}

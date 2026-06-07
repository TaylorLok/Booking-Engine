<?php

namespace App\Http\Requests\Booking;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', 'integer', 'exists:rooms,id'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'rooms.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $checkIn = Carbon::parse($this->input('check_in'))->startOfDay();
            $checkOut = Carbon::parse($this->input('check_out'))->startOfDay();
            $nights = $checkIn->diffInDays($checkOut);

            if ($nights > config('booking.max_nights', 30)) {
                $validator->errors()->add('check_out', 'The maximum stay is '.config('booking.max_nights', 30).' nights.');
            }

            $roomIds = collect($this->input('rooms', []))->pluck('room_id')->unique();

            $rooms = Room::query()
                ->whereIn('id', $roomIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            if ($rooms->count() !== $roomIds->count()) {
                $validator->errors()->add('rooms', 'One or more selected rooms are unavailable.');

                return;
            }

            foreach ($this->input('rooms', []) as $index => $selection) {
                $room = $rooms->get($selection['room_id']);

                if ($room === null) {
                    continue;
                }

                $adults = (int) $selection['adults'];
                $children = (int) ($selection['children'] ?? 0);

                if ($adults > $room->max_adults) {
                    $validator->errors()->add("rooms.{$index}.adults", "This room allows a maximum of {$room->max_adults} adults.");
                }

                if ($children > $room->max_children) {
                    $validator->errors()->add("rooms.{$index}.children", "This room allows a maximum of {$room->max_children} children.");
                }

                if (($adults + $children) > $room->max_occupancy) {
                    $validator->errors()->add("rooms.{$index}.adults", "Total guests exceed this room's maximum occupancy of {$room->max_occupancy}.");
                }
            }
        });
    }
}

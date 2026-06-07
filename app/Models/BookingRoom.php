<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'booking_id',
    'room_id',
    'adults',
    'children',
    'price_per_night_cents',
    'nights_count',
])]
class BookingRoom extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adults' => 'integer',
            'children' => 'integer',
            'price_per_night_cents' => 'integer',
            'nights_count' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @param  Builder<BookingRoom>  $query
     */
    public function scopeWhereDateRangeOverlaps(Builder $query, Carbon|string $checkIn, Carbon|string $checkOut): Builder
    {
        return $query->whereHas('booking', function (Builder $bookingQuery) use ($checkIn, $checkOut): void {
            $bookingQuery
                ->where('check_in', '<', $checkOut)
                ->where('check_out', '>', $checkIn);
        });
    }
}

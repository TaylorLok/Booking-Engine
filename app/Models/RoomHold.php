<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'room_id',
    'booking_id',
    'check_in',
    'check_out',
    'expires_at',
])]
class RoomHold extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'expires_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @param  Builder<RoomHold>  $query
     */
    public function scopeWhereDateRangeOverlaps(Builder $query, Carbon|string $checkIn, Carbon|string $checkOut): Builder
    {
        return $query
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);
    }
}

<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'reference',
    'idempotency_key',
    'user_id',
    'status',
    'check_in',
    'check_out',
    'adults',
    'children',
    'subtotal_cents',
    'taxes_cents',
    'total_cents',
    'special_requests',
    'external_reference',
    'external_response_snapshot',
    'failure_reason',
    'api_attempt_count',
    'confirmed_at',
    'cancelled_at',
])]
class Booking extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'check_in' => 'date',
            'check_out' => 'date',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal_cents' => 'integer',
            'taxes_cents' => 'integer',
            'total_cents' => 'integer',
            'external_response_snapshot' => 'array',
            'api_attempt_count' => 'integer',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function rooms(): HasManyThrough
    {
        return $this->hasManyThrough(Room::class, BookingRoom::class);
    }

    public function roomHolds(): HasMany
    {
        return $this->hasMany(RoomHold::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(BookingStatusEvent::class);
    }
}

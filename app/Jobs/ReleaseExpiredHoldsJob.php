<?php

namespace App\Jobs;

use App\Models\RoomHold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseExpiredHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        RoomHold::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}

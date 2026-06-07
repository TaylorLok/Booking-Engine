<?php

namespace App\Jobs;

use App\Models\RoomHold;
use App\Support\BookingFlowLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseExpiredHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(BookingFlowLogger $logger): void
    {
        $expiredHolds = RoomHold::query()
            ->where('expires_at', '<', now())
            ->get();

        if ($expiredHolds->isEmpty()) {
            $logger->debug('hold.expired_cleanup.none_found');

            return;
        }

        $logger->info('hold.expired_cleanup.started', [
            'expired_hold_count' => $expiredHolds->count(),
        ]);

        foreach ($expiredHolds as $hold) {
            $logger->info('hold.expired_released', [
                ...$logger->hold($hold),
                'reason' => 'expires_at_passed',
            ]);
        }

        RoomHold::query()
            ->whereIn('id', $expiredHolds->pluck('id'))
            ->delete();

        $logger->info('hold.expired_cleanup.completed', [
            'released_count' => $expiredHolds->count(),
        ]);
    }
}

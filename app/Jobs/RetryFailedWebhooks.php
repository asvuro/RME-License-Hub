<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $pendingDeliveries = WebhookDelivery::whereNull('delivered_at')
            ->where('attempts', '<', \DB::raw('max_attempts'))
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->limit(50)
            ->get();

        foreach ($pendingDeliveries as $delivery) {
            try {
                $dispatcher->deliver($delivery);
            } catch (\Throwable $e) {
                Log::error("RetryFailedWebhooks: Failed delivery {$delivery->id}: {$e->getMessage()}");
            }
        }
    }
}

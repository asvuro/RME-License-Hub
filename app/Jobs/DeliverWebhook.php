<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(
        public WebhookDelivery $delivery
    ) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        if ($this->delivery->isDelivered()) {
            return;
        }

        if (! $this->delivery->canRetry()) {
            return;
        }

        $dispatcher->deliver($this->delivery);
    }

    public function backoff(): int
    {
        return min(300, 30 * (2 ** $this->attempts()));
    }
}

<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every active hub admin when a tenant stops phoning home for
 * longer than `license.max_offline_days` (Tenant::isOffline()). Deduped by
 * CheckTenantHeartbeats via tenants.offline_alert_sent_at — sent once per
 * stale period, reset the moment the tenant heartbeats again.
 */
class TenantOfflineNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lastSeen = $this->tenant->last_heartbeat_at?->toDayDateTimeString() ?? 'never';
        $maxDays = (int) config('license.max_offline_days', 14);

        return (new MailMessage)
            ->subject("[RME Hub] Tenant offline: {$this->tenant->client_name} ({$this->tenant->client_code})")
            ->greeting('Tenant belum phone-home')
            ->line("Instalasi **{$this->tenant->client_name}** ({$this->tenant->client_code}) belum mengirim heartbeat lebih dari {$maxDays} hari.")
            ->line("Terakhir terlihat: {$lastSeen}.")
            ->line('Kemungkinan penyebab: server klien mati, jaringan terputus, atau konfigurasi GRUP_HUB_URL/token rusak.')
            ->action('Lihat daftar tenant', route('hub.tenants.index'))
            ->line('Notifikasi ini akan dikirim ulang hanya setelah tenant kembali online lalu offline lagi.');
    }
}

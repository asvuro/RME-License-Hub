<?php

use App\Events\GrupNotification;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

// Private tenant channels - tenant instances authenticate via their
// service-to-service token. The authorization happens through the
// /broadcasting/auth endpoint which is overridden by our custom
// tenant broadcasting auth controller.
Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId) {
    // Hub admin can subscribe to any tenant channel for monitoring
    return true;
});

/*
 * Grup realtime channel authorization (machine-to-machine, NOT a user session).
 *
 * A branch installation subscribes to its own private channel
 * `private-grup.instance.{instance_id}`. Authorization is fail-closed: the auth
 * request must carry the instance bearer token AND an `X-RME-Instance-ID` header
 * that exactly matches the channel's instance id. The hub's dedicated
 * `/api/v1/group/realtime/auth` endpoint performs the same check and returns the
 * Pusher/Reverb signed auth string (see docs/grup-realtime-hub-contract-assumptions.md
 * on the RME-Backend side and docs/reconciliation-with-grup-module.md here).
 */
Broadcast::channel(GrupNotification::CHANNEL_PREFIX.'{instanceId}', function ($user, $instanceId) {
    $requestInstance = (string) request()->header('X-RME-Instance-ID');
    $token = (string) request()->bearerToken();

    if ($requestInstance === '' || $token === '') {
        return false;
    }

    return hash_equals($requestInstance, (string) $instanceId);
});

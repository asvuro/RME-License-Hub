<?php

use App\Events\GrupNotification;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
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

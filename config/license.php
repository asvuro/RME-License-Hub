<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RSA Key Pair for License Token Signing
    |--------------------------------------------------------------------------
    | The hub signs license tokens with its private key. The public key is
    | distributed to client instances (RME-Backend) to verify token authenticity.
    |
    | Token format: base64(json_payload).base64(rsa_sha256_signature)
    | Client verifies via: openssl_verify($payloadJson, $signature, $publicKey, OPENSSL_ALGO_SHA256)
    */
    'private_key_path' => env('LICENSE_PRIVATE_KEY_PATH', storage_path('keys/license_private.pem')),
    'private_key' => env('LICENSE_PRIVATE_KEY', null),

    // Public key that clients use to verify tokens (for distribution documentation)
    'public_key_path' => env('LICENSE_PUBLIC_KEY_PATH', storage_path('keys/license_public.pem')),

    /*
    |--------------------------------------------------------------------------
    | Service-to-Service Authentication
    |--------------------------------------------------------------------------
    | Each tenant receives a unique API token for authenticating heartbeat
    | and sync requests to the hub. The token is SHA-256 hashed in the DB.
    |
    | Clients send: Authorization: Bearer {plaintext_token}
    | Hub verifies: hash('sha256', $token) === tenant.api_token_hash
    */
    's2s_token_prefix' => env('LICENSE_S2S_TOKEN_PREFIX', 'rme_hub_'),

    /*
    |--------------------------------------------------------------------------
    | Force-Disable Policy
    |--------------------------------------------------------------------------
    */
    'force_disable_grace_hours' => (int) env('LICENSE_FORCE_DISABLE_GRACE_HOURS', 72),
    'force_disable_warning_before_days' => (int) env('LICENSE_FORCE_DISABLE_WARNING_BEFORE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */
    'webhook_timeout' => (int) env('LICENSE_WEBHOOK_TIMEOUT', 15),
    'webhook_max_attempts' => (int) env('LICENSE_WEBHOOK_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat Policy
    |--------------------------------------------------------------------------
    | Maximum days the client can run without phone-home heartbeat.
    | After this, the license is marked stale.
    */
    'max_offline_days' => (int) env('LICENSE_MAX_OFFLINE_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Tenant-specific configuration (webhook URLs and secrets)
    |--------------------------------------------------------------------------
    | In production, these are stored encrypted in the database or in a secure
    | vault. For development/testing, they can be set via env.
    */
    'tenants' => [],

    /*
    |--------------------------------------------------------------------------
    | Global Webhook Secret (fallback)
    |--------------------------------------------------------------------------
    | If a tenant has no per-tenant encrypted webhook secret set, this global
    | secret is used to sign pushes to that tenant. Per-tenant secrets always
    | take precedence. Prefer per-tenant secrets in production.
    */
    'global_webhook_secret' => env('LICENSE_GLOBAL_WEBHOOK_SECRET', null),

    /*
    |--------------------------------------------------------------------------
    | Grup Realtime / Relay Egress (hub -> instance, Modules/Grup)
    |--------------------------------------------------------------------------
    | Shared HMAC secret used to SIGN hub->instance calls (the client's
    | VerifyGroupHubSignature middleware). Material: <ts>\n<request_id>\n<raw_body>.
    | Must match GRUP_HUB_HMAC_SECRET configured on the RME-Backend side.
    */
    'grup_hub_hmac_secret' => env('GRUP_HUB_HMAC_SECRET', null),

    /*
    |--------------------------------------------------------------------------
    | Reverb (Realtime Relay for Groups)
    |--------------------------------------------------------------------------
    */
    'reverb' => [
        'host' => env('REVERB_HOST', '127.0.0.1'),
        'port' => env('REVERB_PORT', 8080),
        'scheme' => env('REVERB_SCHEME', 'http'),
        'channel_prefix' => env('REVERB_CHANNEL_PREFIX', 'rme-group'),
    ],
];

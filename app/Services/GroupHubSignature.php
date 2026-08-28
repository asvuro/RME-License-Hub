<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Signs hub -> instance (RME-Backend Modules/Grup) egress requests, matching
 * the client's VerifyGroupHubSignature middleware exactly:
 *   material = "<timestamp>\n<request_id>\n<raw_body>"
 *   header   = X-RME-Signature: sha256=<hmac>
 * Plus the required X-RME-Timestamp, X-RME-Request-ID, X-RME-Group-ID,
 * X-RME-Target-Instance-ID headers. The secret is the per-instance
 * shared GRUP_HUB_HMAC_SECRET configured on both sides.
 *
 * The hub is the single relay, so each instance trusts only the hub's signature.
 */
class GroupHubSignature
{
    public function __construct(
        protected string $secret,
        protected string $groupHubId,
        protected int $toleranceSeconds = 300,
    ) {}

    /**
     * Build the signed header array for a hub -> instance request.
     */
    public function signedHeaders(Tenant $target, string $rawBody): array
    {
        $timestamp = (string) time();
        $requestId = (string) Str::uuid();
        $instanceId = (string) ($target->licenseKeys()->latest()->value('instance_id') ?? '');

        $material = $timestamp."\n".$requestId."\n".$rawBody;
        $signature = 'sha256='.hash_hmac('sha256', $material, $this->secret);

        return [
            'X-RME-Timestamp' => $timestamp,
            'X-RME-Request-ID' => $requestId,
            'X-RME-Signature' => $signature,
            'X-RME-Group-ID' => $this->groupHubId,
            'X-RME-Target-Instance-ID' => $instanceId,
        ];
    }

    public function toleranceSeconds(): int
    {
        return $this->toleranceSeconds;
    }
}

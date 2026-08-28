<?php

namespace App\Services\License;

use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use Illuminate\Support\Facades\DB;

/**
 * Issues a new license for a tenant: generates a unique license key, creates the
 * entitlement snapshot from the chosen tier, and triggers the signing process.
 *
 * The actual effective-quota recalculation (base + add-ons) lives in the core
 * backend's entitlement service; this dashboard service only creates the initial
 * entitlement and delegates signing to {@see LicenseSigningService}.
 */
class LicenseIssuer
{
    public function __construct(
        private readonly LicenseSigningService $signer
    ) {}

    /**
     * Generate a unique, human-readable license key of the form
     * RME-XXXX-XXXX-XXXX-XXXX, guaranteed not to collide with an existing row.
     */
    public function generateUniqueKey(): string
    {
        do {
            $key = $this->formatKey();
        } while (LicenseKey::query()->where('license_key', $key)->exists());

        return $key;
    }

    /**
     * Issue a license for the given tenant under the given tier.
     *
     * @param  int|null  $durationDays  Override for the tier's default duration.
     */
    public function issue(Tenant $tenant, Tier $tier, ?int $durationDays = null): LicenseKey
    {
        return DB::transaction(function () use ($tenant, $tier, $durationDays) {
            $duration = $durationDays ?? $tier->default_duration_days;
            $validUntil = now()->addDays($duration);

            $licenseKey = LicenseKey::create([
                'tenant_id' => $tenant->id,
                'license_key' => $this->generateUniqueKey(),
                'status' => 'active',
                'issued_at' => now(),
                'valid_until' => $validUntil,
            ]);

            $entitlement = LicenseEntitlement::create([
                'license_key_id' => $licenseKey->id,
                'tenant_id' => $tenant->id,
                'tier_id' => $tier->id,
                'status' => 'active',
                'base_max_users' => $tier->base_max_users,
                'base_max_branches' => 1,
                'effective_max_users' => $tier->base_max_users,
                'effective_max_branches' => 1,
                'effective_modules' => $tier->included_modules ?? [],
                'valid_from' => now(),
                'valid_until' => $validUntil,
                'last_recalculated_at' => now(),
            ]);

            // Trigger the (stub) signing process. The returned artifact is what
            // would be delivered to the client SIMRS for offline verification.
            $this->signer->sign($licenseKey, $this->payloadFor($licenseKey, $entitlement));

            return $licenseKey;
        });
    }

    /**
     * Build the canonical payload that gets signed for a license.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(LicenseKey $licenseKey, LicenseEntitlement $entitlement): array
    {
        return [
            'license_key' => $licenseKey->license_key,
            'tenant_id' => $licenseKey->tenant_id,
            'tier_id' => $entitlement->tier_id,
            'valid_until' => $entitlement->valid_until?->toIso8601String(),
            'effective_max_users' => $entitlement->effective_max_users,
            'effective_max_branches' => $entitlement->effective_max_branches,
            'effective_modules' => $entitlement->effective_modules ?? [],
        ];
    }

    /**
     * Produce the signed license artifact (key + signature) for display / delivery.
     */
    public function signedArtifact(LicenseKey $licenseKey): string
    {
        $entitlement = $licenseKey->entitlement;

        if (! $entitlement) {
            return $licenseKey->license_key;
        }

        $signature = $this->signer->sign($licenseKey, $this->payloadFor($licenseKey, $entitlement));

        return $licenseKey->license_key.':'.$signature;
    }

    private function formatKey(): string
    {
        $segment = static fn () => strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        return 'RME-'.$segment().'-'.$segment().'-'.$segment().'-'.$segment();
    }
}

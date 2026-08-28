<?php

namespace App\Services\License;

use App\Models\LicenseKey;

/**
 * Produces a cryptographic signature over a license payload.
 *
 * STUB IMPLEMENTATION — replace the body of {@see sign()} with real asymmetric
 * signing (EdDSA / RSA) before production. The contract below is stable so the
 * rest of the dashboard can be wired now and the real crypto dropped in later
 * without touching callers.
 *
 * Design notes for the real implementation:
 *  - Keep the hub's private key in a secret store (never in the DB or .env of the app box).
 *  - The client SIMRS verifies the signature against the hub's published public key,
 *    enabling offline verification of license authenticity.
 *  - {@see sign()} must remain deterministic for the same payload so {@see verify()} works.
 */
class LicenseSigningService
{
    /**
     * Sign a canonical license payload and return the signature string.
     */
    public function sign(LicenseKey $licenseKey, array $payload): string
    {
        // STUB: HMAC-style placeholder using the app key. Swap for EdDSA/RSA.
        // Sort keys recursively so the canonical payload is deterministic for signing.
        $canonical = json_encode(
            $this->canonicalize($payload),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );

        return 'STUB:'.hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    /**
     * Recursively sort an array by key for a deterministic canonical form.
     */
    private function canonicalize(array $data): array
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalize($value);
            }
        }

        return $data;
    }

    /**
     * Verify a signature produced by {@see sign()}.
     */
    public function verify(LicenseKey $licenseKey, array $payload, string $signature): bool
    {
        return hash_equals($this->sign($licenseKey, $payload), $signature);
    }
}

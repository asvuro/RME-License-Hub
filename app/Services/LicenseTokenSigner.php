<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Signs license tokens in the exact format expected by SystemLicenseGuard
 * on the client side (RME-Backend).
 *
 * Token format: base64(json_payload) . '.' . base64(raw_rsa_sha256_signature)
 *
 * The client verifies via:
 *   openssl_verify($payloadJson, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256)
 *
 * So we must sign the raw JSON string (not base64) with RSA-SHA256,
 * then base64-encode both parts.
 */
class LicenseTokenSigner
{
    private ?string $privateKey = null;

    /**
     * Get the RSA private key for signing tokens.
     * In production, set via LICENSE_PRIVATE_KEY_PATH or LICENSE_PRIVATE_KEY env.
     */
    public function getPrivateKey(): ?string
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }

        $path = config('license.private_key_path');
        if ($path && file_exists($path)) {
            $key = file_get_contents($path);
            if ($key) {
                $this->privateKey = $key;

                return $key;
            }
        }

        $inlineKey = config('license.private_key');
        if ($inlineKey) {
            $this->privateKey = $inlineKey;

            return $inlineKey;
        }

        return null;
    }

    /**
     * Sign a payload array and return the token string.
     *
     * @param  array  $payload  The license payload
     * @return string The token: base64(payload_json).base64(signature)
     *
     * @throws \RuntimeException If private key is not available or signing fails
     */
    public function sign(array $payload): string
    {
        $privateKey = $this->getPrivateKey();
        if (! $privateKey) {
            Log::error('LicenseTokenSigner: No RSA private key configured.');
            throw new \RuntimeException('License signing key is not configured.');
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signature = '';
        $result = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $result) {
            $error = openssl_error_string();
            Log::error('LicenseTokenSigner: openssl_sign failed: '.$error);
            throw new \RuntimeException('Failed to sign license token: '.$error);
        }

        return base64_encode($payloadJson).'.'.base64_encode($signature);
    }

    /**
     * Build the full license payload matching what the client expects.
     *
     * Required by client (LicenseVerifierService::activateToken):
     *   instance_id, client_name, client_code, license_key,
     *   hardware_id, valid_until, allowed_modules
     *
     * Optional but recommended:
     *   tier, issued_at, max_users
     */
    public function buildPayload(
        string $instanceId,
        string $clientName,
        string $clientCode,
        string $licenseKey,
        string $hardwareId,
        string $tier,
        string $issuedAt,
        string $validUntil,
        int $maxUsers,
        array $allowedModules,
        array $extra = []
    ): array {
        return array_merge([
            'instance_id' => $instanceId,
            'client_name' => $clientName,
            'client_code' => $clientCode,
            'license_key' => $licenseKey,
            'hardware_id' => $hardwareId,
            'tier' => $tier,
            'issued_at' => $issuedAt,
            'valid_until' => $validUntil,
            'max_users' => $maxUsers,
            'allowed_modules' => $allowedModules,
        ], $extra);
    }

    /**
     * Build and sign in one step.
     */
    public function buildAndSign(
        string $instanceId,
        string $clientName,
        string $clientCode,
        string $licenseKey,
        string $hardwareId,
        string $tier,
        string $issuedAt,
        string $validUntil,
        int $maxUsers,
        array $allowedModules,
        array $extra = []
    ): string {
        $payload = $this->buildPayload(
            $instanceId, $clientName, $clientCode, $licenseKey,
            $hardwareId, $tier, $issuedAt, $validUntil,
            $maxUsers, $allowedModules, $extra
        );

        return $this->sign($payload);
    }
}

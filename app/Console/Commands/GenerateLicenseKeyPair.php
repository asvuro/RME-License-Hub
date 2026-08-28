<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateLicenseKeyPair extends Command
{
    protected $signature = 'license:generate-keys {--force : Overwrite existing keys}';
    protected $description = 'Generate RSA key pair for signing license tokens';

    public function handle(): int
    {
        $dir = storage_path('keys');
        $privatePath = config('license.private_key_path');
        $publicPath = config('license.public_key_path');

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0700, true);
        }

        if (File::exists($privatePath) && !$this->option('force')) {
            $this->warn('Private key already exists. Use --force to overwrite.');
            return 1;
        }

        $this->info('Generating RSA key pair (2048 bits)...');

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyPair = openssl_pkey_new($config);
        if (!$keyPair) {
            $this->error('Failed to generate key pair: ' . openssl_error_string());
            return 1;
        }

        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        File::put($privatePath, $privateKey);
        File::put($publicPath, $publicKey);
        File::chmod($privatePath, 0600);
        File::chmod($publicPath, 0644);

        $this->info("Private key saved to: {$privatePath}");
        $this->info("Public key saved to: {$publicPath}");
        $this->warn('Distribute the public key to client instances (RME-Backend) via:');
        $this->warn("  SAAS_LICENSE_PUBLIC_KEY_PATH or inline via SAAS_LICENSE_PUBLIC_KEY env var.");

        return 0;
    }
}

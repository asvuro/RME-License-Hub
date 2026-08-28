<?php

namespace Database\Seeders;

use App\Models\HubAdmin;
use App\Models\ModuleModel;
use App\Models\Tier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create default tiers
        $tiers = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'Paket dasar untuk klinik kecil',
                'base_max_users' => 10,
                'default_duration_days' => 365,
                'included_modules' => ['MedicalRecordAnamnesis', 'LayananPrescription'],
                'metadata' => ['price_monthly' => 500000, 'price_yearly' => 5000000],
            ],
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'description' => 'Paket standar untuk klinik menengah',
                'base_max_users' => 50,
                'default_duration_days' => 365,
                'included_modules' => ['MedicalRecordAnamnesis', 'LayananPrescription', 'SatuSehatIgd', 'Laboratorium'],
                'metadata' => ['price_monthly' => 1500000, 'price_yearly' => 15000000],
            ],
            [
                'slug' => 'pro',
                'name' => 'Professional',
                'description' => 'Paket profesional untuk RS kecil-menengah',
                'base_max_users' => 200,
                'default_duration_days' => 365,
                'included_modules' => [
                    'MedicalRecordAnamnesis', 'LayananPrescription', 'SatuSehatIgd',
                    'Laboratorium', 'Radiologi', 'KamarOperasi', 'Billing',
                    'Farmasi', 'RekamMedis',
                ],
                'metadata' => ['price_monthly' => 5000000, 'price_yearly' => 50000000],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Paket enterprise untuk RS besar (unlimited users)',
                'base_max_users' => 0, // unlimited
                'default_duration_days' => 365,
                'included_modules' => ['*'],
                'metadata' => ['price_monthly' => 15000000, 'price_yearly' => 150000000],
            ],
        ];

        foreach ($tiers as $tier) {
            Tier::firstOrCreate(['slug' => $tier['slug']], $tier);
        }

        // Create sample modules
        $modules = [
            'MedicalRecordAnamnesis' => 'Medical Record Anamnesis',
            'LayananPrescription' => 'Layanan Prescription',
            'SatuSehatIgd' => 'SatuSehat IGD',
            'Laboratorium' => 'Laboratorium',
            'Radiologi' => 'Radiologi',
            'KamarOperasi' => 'Kamar Operasi',
            'Billing' => 'Billing',
            'Farmasi' => 'Farmasi',
            'RekamMedis' => 'Rekam Medis',
        ];

        foreach ($modules as $slug => $name) {
            ModuleModel::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        // Create default hub admin
        HubAdmin::firstOrCreate(
            ['email' => 'admin@rme-hub.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('changeme123'),
                'role' => 'superadmin',
                'is_active' => true,
            ]
        );
    }
}

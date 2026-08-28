# Deployment — RME-License-Hub

Panduan setup dari nol untuk hub lisensi pusat RME (SIMRS). Hub ini di-deploy
sebagai aplikasi Laravel dengan proses terpisah: **web (php-fpm + nginx)**,
**Reverb (WebSocket)**, **queue worker**, dan **scheduler**. State disimpan di
**MariaDB** + **Redis**.

Kontrak realtime Grup yang di-deploy di sini direkonsiliasi dengan
`Modules/Grup` (RME-Backend) — lihat `docs/reconciliation-with-grup-module.md`.

---

## 0. Prasyarat

- Docker Engine + Docker Compose v2 (`docker compose version`).
- PHP 8.4 (khusus CLI lokal untuk generate key; server pakai image `php:8.4-fpm`).
  > Catatan: PHP 8.5 **tidak** dipakai — image 8.5 pernah gagal build pada tooling
  > tim (ekstensi `pcntl` belum stabil), jadi konsisten dengan `docker/loadtest`.
- OpenSSL (untuk generate RSA keypair penandatanganan lisensi).
- Domain publik untuk `APP_URL` / `REVERB_HOST` (mis. `hub.yourdomain.com`).

---

## 1. Clone & environment

```bash
git clone <repo-url> rme-license-hub
cd rme-license-hub

cp .env.example .env
# Edit .env — minimal isi: APP_URL, DB_*, REDIS_*, SAAS_WEBHOOK_SECRET,
# GRUP_HUB_HMAC_SECRET, domain pada REVERB_HOST.
```

---

## 2. Generate RSA key pair (license signing)

Hub menandatangani token lisensi dengan RSA-SHA256; klien (RME-Backend
`SystemLicenseGuard`) memverifikasi dengan public key.

Gunakan command artisan bawaan — sudah ada di `app/Console/Commands/GenerateLicenseKeyPair.php`:

```bash
php artisan license:generate-keys
# Pakai --force untuk menimpa kunci yang sudah ada (mis. rotasi terjadwal).
```

Ini menulis `storage/keys/license_private.pem` (chmod 600) dan
`storage/keys/license_public.pem` (chmod 644) — path dibaca dari
`config('license.private_key_path')` / `public_key_path`, default sudah cocok
dengan `.env.example`. Distribusikan **hanya** file `license_public.pem` ke
setiap instalasi RME-Backend sebagai konfigurasi verifikasi
`SystemLicenseGuard`. **Jangan** pernah mengirim private key ke client.

Kalau lebih suka OpenSSL manual (hasil identik):

```bash
mkdir -p storage/keys
openssl genpkey -algorithm RSA -out storage/keys/license_private.pem \
    -pkeyopt rsa_keygen_bits:2048
openssl rsa -in storage/keys/license_private.pem \
    -pubout -out storage/keys/license_public.pem
chmod 600 storage/keys/license_private.pem
```

---

## 3. Build & migrate via Docker

```bash
# Build image (php-fpm + composer install + autoload optimize).
docker compose build

# Generate APP_KEY (laravel).
docker compose run --rm hub-app php artisan key:generate

# Jalankan database & redis dulu, lalu migrate.
docker compose up -d hub-db hub-redis
docker compose run --rm hub-app php artisan migrate --force

# (Opsional) seed data awal admin/tenant.
docker compose run --rm hub-app php artisan db:seed
```

---

## 4. Generate Reverb credentials

Reverb butuh `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET`.

```bash
docker compose run --rm hub-app php artisan reverb:generate
# Perintah di atas mengisi .env. Pastikan REVERB_HOST = hostname publik
# (mis. hub.yourdomain.com) dan REVERB_PORT=8080, REVERB_SCHEME=https.
```

Client `Modules/Grup` memakai `GRUP_REVERB_HOST`, `GRUP_REVERB_PORT`, dan
`GRUP_REVERB_APP_KEY` — isi di sisi client dengan nilai publik yang sama
(nama host publik, bukan `hub-reverb` internal).

---

## 5. Secrets service-to-service (Grup contract)

Isi di `.env` (harus cocok dengan yang dikonfigurasi di sisi client `Modules/Grup`):

```env
SAAS_WEBHOOK_SECRET=<random>        # X-Hub-Signature-256 ke client
GRUP_HUB_HMAC_SECRET=<random>       # HMAC hub→instance (VerifyGroupHubSignature)
GRUP_HUB_TIMESTAMP_TOLERANCE=300
```

Generate acak misalnya:

```bash
openssl rand -hex 32
```

Catatan keamanan: secret HMAC **per-instance** (dapat dirotasi). Mekanisme rotasi
tanpa downtime belum final — lihat §"Rotasi" di `docs/reconciliation-with-grup-module.md`.

---

## 5a. ⚠️ JEBAKAN: `config:cache` sebelum test bisa menghapus data sungguhan

Ditemukan sendiri lewat pengalaman langsung (2026-08-28): kalau `php artisan
config:cache` PERNAH dijalankan di suatu environment (server dev/staging, atau
container yang sama dipakai untuk deploy DAN test), maka `php artisan test`
setelahnya **DIAM-DIAM MENGABAIKAN** override `DB_DATABASE=:memory:` di
`phpunit.xml` — karena `config()` membaca file cache statis, bukan
mengevaluasi ulang `env()`. Efeknya: `RefreshDatabase` di test suite akan
migrate-fresh (drop semua tabel lalu isi ulang kosong) **database sungguhan**
yang dipakai `config:cache` tadi, bukan `:memory:`.

**Aturan wajib**: JANGAN PERNAH jalankan `php artisan config:cache` di
container/proses yang sama dengan yang dipakai untuk `php artisan test` —
pisahkan environment build (boleh `config:cache`) dari environment CI/test
(jangan pernah `config:cache`, atau jalankan `php artisan config:clear`
tepat sebelum `php artisan test`). Skrip CI harus eksplisit
`config:clear && artisan test`, bukan asumsi container test selalu bersih.

---

## 6. Jalankan semua layanan

```bash
docker compose up -d
```

Layanan yang berjalan:

| Service        | Proses                          | Catatan |
|----------------|---------------------------------|--------|
| `hub-nginx`    | nginx (edge + TLS + /app proxy) | port 80/443 |
| `hub-app`      | php-fpm                         | dipanggil nginx |
| `hub-db`       | MariaDB 11.4                    | volume persisten |
| `hub-redis`    | Redis 7                         | cache/queue/reverb scaling |
| `hub-reverb`   | `php artisan reverb:start`      | WebSocket :8080 (proxy /app) |
| `hub-queue`    | `php artisan queue:work`        | antrian default,grup,webhooks |
| `hub-scheduler`| `php artisan schedule:work`     | retry webhook / prune |

Cek status:

```bash
docker compose ps
docker compose logs -f hub-reverb hub-queue
```

---

## 7. TLS (produksi)

`docker/nginx.conf` sudah mem-proxy `/app/` ke Reverb. Untuk HTTPS:

1. Letakkan sertifikat di `docker/tls/` (volume `hub-tls`) dan aktifkan blok
   `listen 443 ssl` di nginx (template sudah menyediakan pemetaan volume).
2. Set `REVERB_SCHEME=https` dan `REVERB_HOST=<domain publik>` di `.env`.
3. Client wajib `wss` — `Modules/Grup` menolak scheme non-wss di produksi.

---

## 8. Verifikasi kontrak Grup

Setelah deploy, verifikasi channel/auth sesuai `docs/reconciliation-with-grup-module.md`:

- Channel per branch: `private-grup.instance.{instance_id}` (private).
- Event tunggal: `grup.notification`.
- Auth endpoint: `POST /api/v1/group/realtime/auth` (kembalikan signed auth).
- Payload non-PHI: `event_id, type, resource_id, source_branch_id, version, occurred_at`.

Uji koneksi Reverb dari client:

```bash
docker compose run --rm hub-app php artisan tinker
# >> broadcast(new \App\Events\GrupNotification('inst_xxx', \App\Enums\GroupRealtimeEventType::MembershipUpdated))->toOthers();
```

---

## 9. Pembaruan (upgrade)

```bash
git pull
docker compose build
docker compose run --rm hub-app php artisan migrate --force
docker compose up -d
```

---

## Troubleshooting

- **Reverb tidak terhubung dari client**: pastikan `REVERB_HOST`/.env client =
  domain publik (bukan `hub-reverb`), dan nginx mem-proxy `/app/`. Cek
  `docker compose logs hub-reverb`.
- **Queue tidak jalan**: pastikan `QUEUE_CONNECTION=redis` & Redis sehat.
  `docker compose run --rm hub-app php artisan queue:monitor`.
- **Migrate gagal**: pastikan `hub-db` `service_healthy` (healthcheck MariaDB).
  `docker compose logs hub-db`.
- **Signature webhook ditolak client**: `SAAS_WEBHOOK_SECRET` harus identik di
  hub dan di konfigurasi client.

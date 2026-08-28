# Rekonsiliasi Kontrak Hub ↔ Modul `Grup` (RME-Backend)

Tanggal: 2026-08-28
Penulis: agen hub (worktree `hermes-83732910`)
Sumber kebenaran (codex/agent lain): `~/Documents/Dev/RME-Backend/Modules/Grup/`
Asumsi provisional codex: `~/Documents/Dev/RME-Backend/docs/grup-realtime-hub-contract-assumptions.md`

Tujuan dokumen ini: menjadi catatan lintas-tim yang bisa di-cross-check oleh PT ke
tim RME-Backend. Semua keputusan di bawah sudah diselaraskan ke kode `Modules/Grup`
yang **sudah dibangun**, bukan ke draf README semata.

---

## 1. Ringkasan Perbedaan yang Ditemukan

| # | Aspek | Draf README hub (awal) | Yang dibangun di `Modules/Grup` (codex) | Status |
|---|-------|------------------------|------------------------------------------|--------|
| 1 | Channel Reverb | `group.{group_id}.branch.{branch_instance_id}` + `presence.group.{group_id}` | `private-grup.instance.{instance_id}` (private, 1 per branch) | **BEDA** → diselaraskan ke codex |
| 2 | Event Reverb | 6 event berbeda (`GroupMemberJoined`, `GroupMemberLeft`, `GroupLicenseUpdated`, `GroupQuotaChanged`, `GroupForceDisableWarning`, `GroupForceDisableExecuted`) | 1 event `grup.notification` + payload `type` | **BEDA** → diselaraskan ke codex |
| 3 | Payload Reverb | tidak dispesifikkan bentuk pasti | `{event_id,type,resource_id,source_branch_id,version,occurred_at}`, non-PHI | **BEDA** → diselaraskan ke codex |
| 4 | Auth Reverb | tidak dispesifikkan | `POST /api/v1/group/realtime/auth` mengembalikan signed auth Pusher/Reverb | **BARU** (codex) → diadopsi |
| 5 | Format module status | tidak pasti (full vs delta) | full `modules_statuses` overwrite via webhook `POST /v1/system/license/modules-sync` | **DISERAHKAN** ke Decision 3 |
| 6 | Force-disable | tidak pasti (hub push vs client compute) | hub push via webhook `license.*` / `addon.*` / `quota.*` | **DISERAHKAN** ke Decision 4 |
| 7 | Service-to-service auth | "API tokens with scopes" (umum) | Bearer `GRUP_HUB_TOKEN` + header `X-RME-Instance-ID` (REST egress); HMAC-SHA256 (`GRUP_HUB_HMAC_SECRET`) untuk ingress hub→instance | **DIVALIDASI** — lihat §4 |

### Bukti kode (codex side)

- Channel prefix: `config/grup.php` → `'channel_prefix' => env('GRUP_REVERB_CHANNEL_PREFIX', 'private-grup.instance.')`
- Event wire name: `Services/ReverbSubscriber.php` → `if (($message['event'] ?? null) !== 'grup.notification') continue;`
- Payload schema: `Services/RealtimeEventProcessor.php` → validasi `type in:membership.updated,patient.updated,referral.created,referral.updated`, `event_id uuid`, `version int`, `occurred_at date`.
- Listener: `Console/Commands/ListenGroupRealtimeCommand.php` (`php artisan grup:listen`), wajib `GRUP_REVERB_ENABLED=true`.
- REST egress (client→hub): `Services/GroupHubClient.php` → `GET /api/v1/group/context`, `/api/v1/group/relay/...`, `POST /api/v1/group/realtime/auth`.
- Ingress signature (hub→instance): `Http/Middleware/VerifyGroupHubSignature.php` → header `X-RME-Timestamp`, `X-RME-Request-ID`, `X-RME-Signature`, `X-RME-Group-ID`, `X-RME-Target-Instance-ID`; material HMAC = `<timestamp>\n<request_id>\n<raw_body>`; nonce unik via tabel `grup_hub_nonces`; toleransi 300s.

---

## 2. Keputusan Akhir (Resolved Decisions)

### Decision 1 — Channel naming: `private-grup.instance.{instance_id}`
- **Adopsi** format codex. Alasan keamanan/konsistensi:
  - Fail-closed: branch hanya bisa menerima channel miliknya sendiri (`instance_id`
    unik per lisensi). Tidak ada alamat cross-branch yang bisa diturunkan.
  - Tidak membocorkan topologi grup ke dalam nama channel (kebalikan dari
    `group.{group_id}.branch.{...}` yang memperlihatkan keanggotaan grup).
  - Sudah diimplementasikan penuh di codex (`ReverbSubscriber`, `config/grup.php`).
- **Aksi hub:** `routes/channels.php` mengotorisasi channel
  `private-grup.instance.{instanceId}` hanya bila `X-RME-Instance-ID` cocok;
  `app/Events/GrupNotification.php` menyiarkan ke channel tersebut.

### Decision 2 — Tidak ada presence channel
- **Drop** `presence.group.{group_id}`. Alasan:
  - Presence channel menyiarkan daftar branch ke semua anggota → permukaan serangan
    & kebocoran topologi.
  - codex tidak menggunakannya; status online diambil dari `last_seen_at` di
    `GET /api/v1/group/context`.
- Reverb tetap murni sebagai sinyal invalidasi.

### Decision 3 — Module status: FULL push
- Hub mengirim **seluruh** peta `modules_statuses` (idempotent overwrite), bukan
  delta. Replay-safe (event terlewat → state tetap benar saat berikutnya) dan
  mudah diaudit. Ukuran payload kecil untuk katalog modul RME (~puluhan).

### Decision 4 — Force-disable: hub-authoritative
- Hub yang **menghitung dan mengirim** perintah force-disable (warning + execute).
  Client menerapkan fail-closed, **tidak** menghitung eligibility/target sendiri.
- Alasan: hub memegang single source of truth untuk kuota/lisensi dan aturan
  "jangan pernah disable admin terakhir" + "user terbaru didisable duluan". Kebijakan
  di client akan divergen antar-branch dan berisiko disable salah.

### Decision 5 — Add-on renewal: hub-internal, tanpa data pembayaran di channel grup
- Integrasi renewal/pembayaran adalah urusan billing internal hub (di luar kontrak
  realtime `Grup`). Channel Reverb grup hanya membawa event invalidasi non-PHI
  (lihat §3); **tidak ada** payload pembayaran/billing.

---

## 3. Kontrak Final (single source of truth)

**Channel:** `private-grup.instance.{instance_id}` (private, 1 per branch)
**Event:** `grup.notification`
**Payload:**
```json
{
  "event_id": "uuid",
  "type": "membership.updated | patient.updated | referral.created | referral.updated",
  "resource_id": "string | null",
  "source_branch_id": "uuid | null",
  "version": 1,
  "occurred_at": "ISO-8601"
}
```
**Auth channel:** `POST /api/v1/group/realtime/auth` `{socket_id, channel_name}` → signed auth.
**Ph Principle:** PHI tidak pernah di payload; di-fetch via REST relay (`/api/v1/group/relay/...`).

---

## 4. Item Tambahan yang Perlu Di-cross-check ke Tim RME-Backend

1. **Ketidakcocokan path REST ingress vs egress (PENTING).**
   - `GroupHubClient` (egress client→hub) memanggil `/api/v1/group/relay/...`
     dan `/api/v1/group/realtime/auth`.
   - `docs/grup-realtime-hub-contract-assumptions.md` (dan `routes/api.php` Grup)
     **mendefinisikan ingress hub→instance** di `/api/v1/grup/relay/...`
     (perhatikan `grup` vs `group`).
   - Ini dua arah berbeda (client→hub vs hub→client) sehingga *bisa* memang
     sengaja berbeda kata (`group` untuk egress, `grup` untuk ingress). Namun
     karena mudah tertukar, PT harus mengunci: **egress = `/api/v1/group/*`,
     ingress = `/api/v1/grup/relay/*`** dan menuliskannya eksplisit di kontrak
     final. Hub wajib mengimplementasikan kedua sisi dengan prefix tersebut.

2. **Rotasi token/HMAC tanpa downtime.** `config/grup.php` menyimpan
   `GRUP_HUB_TOKEN`/`GRUP_HUB_HMAC_SECRET` sebagai string tunggal. Perlu mekanisme
   rotasi (mis. dua secret aktif) sebelum produksi — belum ada di kode sejauh ini.

3. **Retensi idempotency key & nonce.** Client memangkas nonce `grup_hub_nonces`
   > 1 hari (`GrupServiceProvider`). Hub harus menyepakati jendela retensi
   `Idempotency-Key` yang sama agar retry aman.

4. **`contract_version`.** Payload sudah membawa `version` (saat ini `1`). Hub dan
   client harus mem validasi `version` dan menolak versi tak dikenal (belum
   diimplementasikan di `RealtimeEventProcessor` — hanya `min:1`).

5. **Fallback delivery.** `POST /api/v1/grup/relay/notifications` ada sebagai
   fallback signed HMAC. Asumsi codex menyarankan *durable pull cursor*
   (`GET /api/v1/grup/events`) lebih disukai untuk recovery listener offline —
   hub perlu menyediakan keduanya (sudah ada `RealtimeNotificationController::index`).

---

## 5. Perubahan Kode di Hub (worktree ini)

- `app/Events/GrupNotification.php` — event broadcast `grup.notification` ke
  `private-grup.instance.{instance_id}` (sumber kontrak hub).
- `app/Enums/GroupRealtimeEventType.php` — enum `type` yang diizinkan (sinkron
  dengan validasi `RealtimeEventProcessor` codex).
- `routes/channels.php` — otorisasi fail-closed channel grup.
- `README.md` — "Open Questions" → "Resolved Decisions"; section Reverb ditulis
  ulang sesuai kontrak final.

Catatan: agen lain (codex) membangun sisi client `Modules/Grup`; perubahan di sini
hanya menyelaraskan **nama/bentuk data sisi hub** dan tidak merombak kode codex.

---

## 6. Verifikasi END-TO-END sungguhan (2026-08-28, pasca-rekonsiliasi awal)

Dokumen ini di atas ditulis dari HASIL BACA KODE kedua sisi (analisis statis),
belum pernah dijalankan bersamaan. Setelah backend hub selesai, dilakukan
verifikasi nyata: `php artisan serve` hub (port 8090) + `GroupHubClient` ASLI
dari RME-Backend (tanpa modifikasi) dipanggil lewat `php artisan tinker`
langsung ke hub yang hidup, melalui HTTP sungguhan (bukan mock/test-double).

**2 bug NYATA ditemukan** — keduanya TIDAK ketahuan oleh test unit terisolasi
di hub karena fixture test hub kebetulan meniru asumsi salah yang SAMA dengan
implementasinya sendiri (bug tersembunyi di balik konsistensi internal palsu):

1. `POST /api/v1/group/realtime/auth` menolak SEMUA request asli dengan 403.
   Sebab: hub membandingkan `channel_name` yang dikirim client (wire name
   asli SDK Pusher/Reverb, `private-grup.instance.{id}`) dengan
   `$expectedChannel` yang lupa prefix `private-` (hub cuma pakai
   `GrupNotification::CHANNEL_PREFIX` mentah = `grup.instance.{id}`).
2. Setelah #1 diperbaiki, endpoint yang sama gagal 500 "Reverb app secret not
   configured". Sebab: hub baca `config('reverb.apps.0.secret')`, padahal
   struktur asli package Reverb (provider `config`) menaruhnya di
   `config('reverb.apps.apps.0.secret')` (nested sekali lagi di bawah key
   `apps`).

Setelah kedua fix, diverifikasi END-TO-END lewat `GroupHubClient` asli
(bukan test hub yang saya tulis sendiri, supaya bebas dari bias asumsi yang
sama): `context()`, `realtimeAuth()` (HMAC diverifikasi kriptografis cocok),
`createReferral()`, `updateReferral()` (siklus create → accept) — SEMUA
round-trip benar melalui HTTP sungguhan antar dua repo terpisah.

**Pelajaran untuk verifikasi kontrak lintas-repo ke depan**: test unit yang
menulis fixture-nya sendiri berdasarkan pemahaman implementasi yang SAMA
(bukan spesifikasi independen) tidak bisa mendeteksi bug asumsi bersama.
Verifikasi sungguhan (kedua sisi hidup, saling panggil via HTTP nyata) adalah
satu-satunya cara menutup celah ini — sudah terbukti menemukan 2 bug yang
lolos dari 76 test hub yang semuanya hijau.

**Endpoint referral BARU ditambahkan** (tidak ada di rekonsiliasi awal —
ditemukan sebagai gap nyata saat audit kontrak penuh sebelum verifikasi
end-to-end): `GroupHubClient::createReferral()`/`updateReferral()` mem-POST/
PATCH ke `/api/v1/group/relay/referrals`, tapi hub sebelumnya HANYA punya
proxy GET generik — tidak ada tempat menyimpan rujukan. Lihat
`app/Http/Controllers/GroupApiController.php` (`storeReferral`/
`updateReferral`/`listReferrals`/`showReferral`) + tabel `group_referrals`
baru (hub-authoritative, sumber kebenaran status rujukan lintas cabang).

---

## 7. Verifikasi END-TO-END lewat HTTP RME-Backend sungguhan (2026-08-28, lanjutan §6)

Setelah §6 (verifikasi via `GroupHubClient` langsung lewat tinker), dilakukan
verifikasi lebih dalam: `php artisan serve` KEDUA repo bersamaan (hub :8090,
RME-Backend :8000), request HTTP asli ke endpoint RME-Backend sendiri
(`POST /api/v1/grup/referrals`, otentikasi Sanctum user sungguhan dengan RBAC
dinamis, pasien sungguhan dari `GeneralPatient`) — BUKAN memanggil
`GroupHubClient` langsung lagi, supaya jalur penuh (controller → FormRequest
→ service → HTTP client → hub → balik) benar-benar teruji.

**1 bug NYATA ketiga ditemukan**: `GET /api/v1/group/relay/referrals/{id}`
(dipakai `RealtimeEventProcessor::syncReferral()` di client untuk membangun
ulang baris lokal dari event `referral.*`) TIDAK menyertakan
`source_patient_id` maupun `patient_snapshot` di response — field yang justru
WAJIB dibaca client (`$data['source_patient_id']`, `$data['patient_snapshot']`).
Akibatnya sinkronisasi event gagal senyap (`failure_reason` tercatat, retry
tak pernah berhasil). Diperbaiki di `referralPayload()` (GroupApiController) +
test regresi baru (`test_referral_payload_includes_patient_snapshot_for_client_resync`).

**Alur penuh diverifikasi jalan lewat HTTP sungguhan, ujung ke ujung:**
1. `POST /api/v1/grup/referrals` (RME-Backend, user asli) → tersimpan lokal
   DENGAN `hub_referral_id` terisi dari hub asli, snapshot pasien asli ikut
   terkirim.
2. Hub menyimpan record yang identik (`GET .../relay/referrals/{id}` cocok).
3. `PATCH .../relay/referrals/{id}` (simulasi cabang tujuan) → status
   `requested` → `accepted` di hub.
4. Simulasi event `referral.updated` masuk ke `RealtimeEventProcessor::accept()`
   di RME-Backend (mewakili apa yang akan terjadi kalau Reverb terkirim) →
   baris lokal RME-Backend ikut ter-update ke `accepted`. BERHASIL setelah fix
   bug #3 di atas.

**Gap yang MASIH terbuka, terkonfirmasi via pengecekan kode langsung (bukan
dugaan)**: hub `App\Services\WebhookDispatcher` HANYA punya method untuk event
lisensi/force-disable (`dispatchLicenseUpdate`, `dispatchModulesSync`,
`dispatchForceDisableWarning`, dst) — **TIDAK ADA** method untuk push event
Grup (`referral.created/updated`, `membership.updated`, `patient.updated`) ke
endpoint ingress client (`POST /v1/grup/relay/notifications`). Ini artinya
delivery event Grup hub→client **hanya lewat Reverb**, tanpa fallback HTTP
push maupun *durable pull cursor* — sama seperti yang sudah ditandai di §4
poin 5 sebelum verifikasi ini, sekarang terkonfirmasi belum ada implementasi
sama sekali di kode hub (bukan cuma pertanyaan desain terbuka). Perlu
diputuskan & dibangun sebelum produksi: kalau koneksi Reverb sebuah cabang
putus, cabang itu TIDAK PUNYA cara lain mengetahui status rujukannya berubah
sampai ada trigger lain (mis. user buka halaman rujukan dan index() di-refresh
manual — TAPI `index()` di client hanya query tabel LOKAL, bukan re-fetch dari
hub, jadi bahkan itu pun tidak akan ke-update tanpa event masuk).

---

## 8. Gap "fallback delivery" DITUTUP (2026-08-28, lanjutan §7)

Dibangun `WebhookDispatcher::dispatchGroupNotification()`/`deliverGroupNotification()`:
setiap `GroupRelayService::broadcast()` sekarang mengirim DUA jalur sekaligus
— Reverb (instan, butuh koneksi aktif) DAN push HTTP tertandatangani ke
`POST /api/v1/grup/relay/notifications` klien (durable, di-antre, retry
dengan backoff, pakai `event_id` yang SAMA supaya dedup klien membuat kedua
jalur aman diterima bersamaan). Kalau klien membalas 409 (nonce sudah
diproses), dianggap terkirim, bukan gagal — jadi tidak retry selamanya.

**3 bug NYATA lagi ditemukan** saat membangun & memverifikasi jalur ini
sungguhan (total 6 bug sesi ini, §6+§7+§8):

4. `GroupHubSignature` (dipakai push ini DAN proxy data pasien `relayProxy`
   yang sudah ada sejak awal) menandatangani dengan prefix `sha256=` di
   `X-RME-Signature`, tapi `VerifyGroupHubSignature` klien membandingkan hex
   HMAC MENTAH tanpa prefix — **setiap request bertanda tangan dari hub yang
   pernah dibuat, dari awal, selalu gagal verifikasi**. Prefix dihapus.
5. `source_branch_id` dikirim sebagai `instance_id` (string spt `INST-SIB`),
   padahal klien resolve lewat `Branch::where('hub_branch_id', ...)` — kolom
   UUID Tenant hub. Setiap event relay gagal validasi `uuid` di klien.
   Diperbaiki di `GroupRelayService::relayToGroup()` + 2 titik broadcast
   referral di `GroupApiController`.
6. `GET /context` mengembalikan field grup RATA di level atas, padahal
   `MembershipSynchronizer::sync()` klien memvalidasi objek `group` BERSARANG
   (`group.id`, `group.legal_name`, dst). Endpoint ini **sebelumnya nol
   cakupan test** di hub — ditambahkan `GroupContextTest.php` yang menjalankan
   response lewat rules validasi PERSIS milik klien (disalin sengaja) supaya
   pergeseran kontrak seperti ini gagal keras, bukan diam-diam lolos.

**Verifikasi akhir sungguhan**: hub relay → job antrian → push HTTP
bertanda tangan → middleware `VerifyGroupHubSignature` ASLI menerima →
`RealtimeEventProcessor` ASLI memproses → `MembershipSynchronizer` sinkron
→ tabel `Branch`/`Group` lokal klien ter-update benar. Rantai penuh, tanpa
satu pun bagian di-mock.

**Pelajaran diperkuat**: dari 6 bug sesi ini, HAMPIR SEMUA baru ketahuan
setelah menjalankan pemrosesan PENUH di sisi klien (bukan cuma memanggil
satu method client secara terisolasi) — bug #6 khususnya baru muncul satu
langkah SETELAH `context()` sendiri "berhasil" (parsing JSON sukses), karena
gagalnya ada di validasi struktur data SATU LAPIS lebih dalam. Verifikasi
sungguhan harus menjalankan alur BISNIS penuh (event masuk → diproses →
tersimpan), bukan berhenti di "response HTTP-nya 200".

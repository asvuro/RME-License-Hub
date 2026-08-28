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

# RME-License-Hub

Central license management hub for RME (SIMRS) commercial product. Manages licenses for isolated client installations (1 clinic/hospital = 1 RME-Backend+RME-Frontend deployment).

## Business Context

- **Deployment Model**: Each client has their own isolated RME installation (own DB, own `modules_statuses.json` via nwidart/laravel-modules)
- **Hub Role**: Central SaaS application owned by the company, manages licenses for ALL client installations
- **Client-Side Module**: `SystemLicenseGuard` in RME-Backend — this hub is its server-side counterpart

## License Model (5 Dimensions — Fixed)

1. **Tier** — Bundle package (starter/standard/pro/enterprise), not a-la-carte
2. **Tier's Built-in Modules** — Modules included in the tier
3. **Add-ons** — Generic units targeting ONE of: extra module, user quota, branch/group quota, OR time extension (renewal)
4. **User Quota** — Max users allowed
5. **License Duration** — Valid until date

**Effective Entitlement** = Tier base + Sum of all active add-ons (like Stripe Billing: base plan + line items)

## Force-Disable Policy (Critical)

When add-on expires and usage exceeds new quota:
- **Newest-registered users** are force-disabled first (not grandfathered)
- **MUST** send warning notification BEFORE execution (no silent cutoff)
- **MUST NEVER** disable the last remaining admin in a client installation

## Group Feature

- Multiple clinics/branches under same legal entity can form a **Group**
- Group membership determined AT LICENSE ISSUANCE by hub (not negotiated at client instance level)
- Cross-branch realtime via **Laravel Reverb hosted on THIS HUB** — each branch subscribes to its own channel on hub
- Single trusted address (hub) instead of dynamic N sibling addresses (prevents SSRF/attack surface)

## Sync Mechanism

- Hub pushes latest license status to client's `modules_statuses.json`
- Design: polling from client vs push from hub, service-to-service auth

---

## API Contract (for SystemLicenseGuard Client)

### Base URL
```
https://hub.yourdomain.com/api/v1
```

### Authentication
- **Client → Hub**: License Key + Hardware ID (HWID) in request body
- **Hub → Client (Webhook)**: HMAC-SHA256 with shared secret (`SAAS_WEBHOOK_SECRET`)
- **Service-to-Service (Hub internal)**: API tokens with scopes

### Endpoints

#### 1. Activate License
```
POST /api/v1/licenses/activate
```

**Request:**
```json
{
  "license_key": "LIC-XXXX-XXXX-XXXX",
  "hardware_id": "HWID-A1B2-C3D4-E5F6",
  "hostname": "client-server-01",
  "app_version": "2.1.0"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Instance successfully activated with central SaaS hub.",
  "token": "base64payload.base64signature"
}
```

**Response (422):**
```json
{
  "success": false,
  "message": "License key not found / hardware mismatch / quota exceeded / etc."
}
```

The `token` is a cryptographic JWT-like token: `base64(payload).base64(RSA-SHA256(payload))` containing:
```json
{
  "instance_id": "inst_abc123",
  "client_name": "RS Sehat Selalu",
  "client_code": "RS-SEHAT",
  "license_key": "LIC-XXXX-XXXX-XXXX",
  "hardware_id": "HWID-A1B2-C3D4-E5F6",
  "valid_until": "2026-12-31 23:59:59",
  "allowed_modules": ["core", "pasien", "rawat-jalan", "rawat-inap", "farmasi", "laboratorium"],
  "tier": "pro",
  "issued_at": "2026-01-15 10:30:00",
  "max_users": 50
}
```

Client verifies RSA signature using hub's public key, then stores locally.

---

#### 2. Heartbeat / Sync (Polling from Client)
```
POST /api/v1/licenses/heartbeat
```

**Request:**
```json
{
  "instance_id": "inst_abc123",
  "client_code": "RS-SEHAT",
  "license_key": "LIC-XXXX-XXXX-XXXX",
  "hardware_id": "HWID-A1B2-C3D4-E5F6",
  "app_version": "2.1.0",
  "php_version": "8.3.10",
  "timestamp": 1725000000
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Heartbeat acknowledged by central SaaS server.",
  "token": "base64payload.base64signature",  // optional: new token if license changed
  "status": "active"  // or "suspended"
}
```

If `token` present → client re-activates with new token (license updated: extended, modules changed, etc.)
If `status: suspended` → client marks license suspended locally.

---

#### 3. Webhook (Push from Hub to Client)
```
POST /v1/system/license/webhook  (on CLIENT side)
```

**Headers:**
- `X-Hub-Signature-256: sha256=<hmac>`

**Body:**
```json
{
  "event_id": "evt_unique_123",
  "timestamp": 1725000000,
  "event": "license.updated",
  "token": "base64payload.base64signature"
}
```

**Events:**
- `license.updated` — License extended, modules changed, add-ons added/removed → includes new `token`
- `license.suspended` — License suspended by admin → client marks suspended
- `license.revoked` — License permanently revoked
- `addon.expired_warning` — Add-on expiring in N days (for force-disable warning)
- `addon.expired` — Add-on expired, force-disable may trigger
- `quota.warning` — Usage approaching quota (80%, 90%, 95%)
- `quota.exceeded` — Usage exceeded quota, force-disable imminent

Client verifies HMAC, checks `event_id` uniqueness (anti-replay), checks timestamp tolerance (±300s default).

---

#### 4. Module Status Sync (Push from Hub)
```
POST /v1/system/license/modules-sync  (on CLIENT side)
```

**Headers:**
- `X-Hub-Signature-256: sha256=<hmac>`

**Body:**
```json
{
  "event_id": "evt_unique_456",
  "timestamp": 1725000000,
  "event": "modules.updated",
  "modules_statuses": {
    "core": true,
    "pasien": true,
    "rawat-jalan": true,
    "rawat-inap": true,
    "farmasi": true,
    "laboratorium": true,
    "radiologi": false,
    "rekam-medis": false
  }
}
```

Client writes this to `modules_statuses.json` (via nwidart/laravel-modules).

---

### Reverb Channels (for Group Realtime)

Hub hosts Laravel Reverb. Each branch installation runs a supervised listener
(`php artisan grup:listen`) that connects to the hub's Reverb over **`wss` only**
in production. The contract below is **reconciled** with the `Modules/Grup`
implementation in RME-Backend — see `docs/reconciliation-with-grup-module.md`
for the rationale and the diff against the earlier draft assumptions.

**Channel Naming (RECONCILED — Decision 1):**
- Private channel per branch: `private-grup.instance.{instance_id}`
  - `{instance_id}` is the hub-issued `license_keys.instance_id`. One channel per
    branch installation; the hub authorizes subscription only when the calling
    instance's `X-RME-Instance-ID` matches the channel suffix (fail-closed).
- **No presence channel.** Online/presence status is conveyed via `last_seen_at`
  in the `GET /api/v1/group/context` REST response, not via a Reverb presence
  channel (Decision 2 — avoids exposing the branch roster to non-members and keeps
  Reverb purely an invalidation signal).

**Wire event (single, Pusher/Reverb compatible):**
- `grup.notification` — the ONLY event name on the channel.

**Payload (non-PHI; signal only — clients refetch data via REST relay):**
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
The `type` set is fixed; the hub MUST only emit those four values. Events are
de-duplicated by `event_id`. PHI (patient demographics/clinical data) is **never**
in the payload — it is pulled on demand through the `GroupRelay` REST endpoints.

**Auth endpoint (hub → Reverb channel auth):**
- `POST /api/v1/group/realtime/auth` accepts `{socket_id, channel_name}` and
  returns the Pusher/Reverb signed auth string after confirming the channel
  belongs to the calling instance. This mirrors the client's
  `GroupHubClient::realtimeAuth()`.

**Client (RME-Backend `Modules/Grup`) subscribes to:**
- Its own private channel: `private-grup.instance.{my_instance_id}`
- Listens for `grup.notification`; ignores `pusher:ping`/`pusher:connection_*` frames.

---

## Database Schema (Planned)

### `tenants` — Client organizations (legal entities)
- id, name, code (unique), contact_email, contact_phone, address, status, created_at, updated_at

### `tiers` — License tier definitions
- id, code (unique: starter/standard/pro/enterprise), name, description, base_max_users, base_allowed_modules (JSON), base_price, sort_order, is_active, created_at, updated_at

### `addons` — Add-on definitions
- id, code (unique), name, description, target_type (module/user_quota/branch_quota/time_extension), target_value (module name / user count / branch count / days), price, is_active, created_at, updated_at

### `licenses` — Issued licenses
- id, tenant_id, tier_id, license_key (unique), instance_id (unique), hardware_id, client_name, client_code, status (active/expired/suspended/revoked/tampered), issued_at, valid_until, last_synced_at, max_users_override, digital_signature, token_payload, integrity_hash, created_at, updated_at

### `license_addons` — Active add-ons on a license
- id, license_id, addon_id, quantity, starts_at, ends_at, is_active, created_at, updated_at

### `groups` — Branch groups under same legal entity
- id, tenant_id, name, code (unique), max_branches, status, created_at, updated_at

### `group_members` — Branches in a group
- id, group_id, license_id (branch's license), branch_name, branch_code, joined_at, status, created_at, updated_at

### `users` — Admin users on hub (for managing licenses)
- id, name, email, password, role (super_admin/admin/support), is_active, created_at, updated_at

### `webhook_events` — Log of webhooks sent to clients
- id, license_id, event_type, event_id, payload (JSON), response_status, response_body, sent_at, delivered_at, retry_count, created_at, updated_at

### `audit_logs` — Audit trail for license changes
- id, license_id, user_id, action, old_values (JSON), new_values (JSON), ip_address, user_agent, created_at

---

## Implementation Status

| Component | Status | Notes |
|-----------|--------|-------|
| Laravel scaffold | ✅ Done | |
| Database migrations | ✅ Done | 21 migrations, idempotency-tested |
| Eloquent models | ✅ Done | |
| License activation API | ✅ Done | POST /api/v1/licenses/activate, RSA-signed tokens |
| Heartbeat/sync API | ✅ Done | POST /api/v1/licenses/heartbeat |
| Webhook sender (hub→client) | ✅ Done | HMAC + retry logic, SSRF-guarded to instance_url |
| Module status sync | ✅ Done | Full modules_statuses push |
| Force-disable logic | ✅ Done | Hub-authoritative, warning-before-execute, last-admin-protected |
| Grup realtime (Reverb) | ✅ Done | Verified end-to-end against the real RME-Backend client (2 real bugs found+fixed, see docs/reconciliation-with-grup-module.md §6) |
| Grup referral relay | ✅ Done | Hub-authoritative group_referrals store (create/accept/reject lifecycle) |
| Admin dashboard | ✅ Done | Livewire — tenant/tier/addon/license/group CRUD |
| Docker deployment | ✅ Verified for real | Full 7-container stack actually built & run (`docker compose up`), not just validated statically — found & fixed 6 real bugs (Dockerfile layer order, missing .dockerignore, missing PHP redis extension, missing DB_HOST/REVERB_HOST overrides on hub-queue/hub-scheduler). See DEPLOYMENT.md |
| Reverb realtime (actual WebSocket server) | ✅ Verified for real | A real WebSocket client connected through nginx to the containerized Reverb server, authenticated via `/realtime/auth`, and received a live `grup.notification` broadcast — not simulated via manual queue processing |
| Tenant offline monitoring | ✅ Done | `license:check-heartbeats` (hourly) emails hub admins + dashboard widget. `license.max_offline_days` existed in config but was never actually checked before this — verified for real via Docker (real MariaDB, real logged email) |
| Database backup | ✅ Done | `hub:backup-database` (daily 02:00), gzipped mysqldump, retention-pruned (never deletes the newest). Verified for real via Docker — a real dump was produced and validated. RSA key backup is a separate MANUAL procedure (DEPLOYMENT.md §9a) — deliberately not bundled with the DB dump |
| Offsite backup sync | ⏳ Not yet | `hub:backup-database` only writes to local disk (`storage/app/backups`) — no S3/rclone sync configured. Needed before real production |
| Tests (critical paths) | ✅ Done | 76/76 passing — entitlement calc, force-disable, license activation/heartbeat, admin guard, referral flow, migration idempotency |
| Local dev secrets (RSA/webhook/HMAC) | ✅ Generated | Local-testing values only — regenerate fresh on the actual production server, never reuse dev-generated secrets (see DEPLOYMENT.md §2, §5) |
| Live HTTP integration test vs a running RME-Backend instance | ✅ Done | Both `php artisan serve` processes live, real Sanctum-authenticated HTTP call to RME-Backend's `POST /api/v1/grup/referrals`, full round trip through the real hub and back — found + fixed a 3rd real bug (missing `patient_snapshot` in referral GET response). See docs/reconciliation-with-grup-module.md §7 |
| Grup event delivery when Reverb is disconnected | ✅ Done | `WebhookDispatcher::dispatchGroupNotification()` — durable, retried HTTP push to the client's ingress alongside every Reverb broadcast, same event_id so the client's dedup makes both paths safe. Verified end-to-end against the real client. See docs/reconciliation-with-grup-module.md §8 |

---

## Resolved Decisions (reconciled with `Modules/Grup` in RME-Backend)

These were the open questions from the hub design draft. Each was resolved against
the actual `Modules/Grup` implementation (codex's agent) and the provisional
contract in `RME-Backend/docs/grup-realtime-hub-contract-assumptions.md`. Full
diff and rationale in `docs/reconciliation-with-grup-module.md`.

1. **Group channel naming → `private-grup.instance.{instance_id}`** (adopt codex's).
   The hub draft proposed `group.{group_id}.branch.{branch_instance_id}`; codex
   built `private-grup.instance.{instance_id}`. We adopt codex's: the channel key
   is an opaque per-instance id, not a `group_id`+`branch` tuple. This is fail-closed
   — a branch can only ever receive its own channel, no cross-branch address is
   derivable — and it leaks no group topology. Decision 1.

2. **Presence channel → NONE.** The hub draft proposed `presence.group.{group_id}`
   for online status; codex uses no presence channel. We drop the presence channel.
   Online/presence is conveyed via `last_seen_at` in the
   `GET /api/v1/group/context` REST response. Rationale: a presence channel would
   broadcast the full branch roster to every member (more attack surface) and
   Reverb is meant only as an invalidation signal. Decision 2.

3. **Module status format → FULL `modules_statuses` push.** The hub pushes the
   complete `modules_statuses.json` map (idempotent overwrite), not a delta. The
   client writes it verbatim. This is replay-safe (any missed event reconstructs
   full state) and simpler to audit; the small payload size is acceptable for the
   ~tens-of-modules RME catalog. Decision 3.

4. **Force-disable trigger → HUB-AUTHORITATIVE push.** The hub computes and pushes
   force-disable (warning + execute) commands; the client applies them fail-closed
   and NEVER self-computes eligibility or picks targets. Rationale: the hub holds
   the single source of truth for quotas/licences and the "never disable the last
   admin" and "newest users first" rules; a client-computed policy would diverge
   across branches and could disable incorrectly. Decision 4.

5. **Add-on renewal flow → HUB-INTERNAL; no payment data on the group channel.**
   Renewal/payment integration is a hub-internal billing concern (outside the
   `Grup` realtime contract). The group Reverb channel carries only the non-PHI
   invalidation events listed above and never any payment/billing payload.
   Decision 5.

---

## Development Commands

```bash
# Install dependencies
composer install
npm install

# Run migrations
php artisan migrate

# Run tests
php artisan test

# Start dev server
php artisan serve

# Start Reverb
php artisan reverb:start

# Start queue worker
php artisan queue:work
```

## Environment Variables (Key)

```env
APP_KEY=
APP_URL=https://hub.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rme_license_hub
DB_USERNAME=
DB_PASSWORD=

# RSA Keys for license signing
LICENSE_PRIVATE_KEY_PATH=storage/keys/license_private.key
LICENSE_PUBLIC_KEY_PATH=storage/keys/license_public.key

# Webhook secret (shared with clients)
SAAS_WEBHOOK_SECRET=

# Reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```
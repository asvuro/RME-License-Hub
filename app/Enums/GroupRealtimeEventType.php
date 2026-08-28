<?php

namespace App\Enums;

/**
 * Event "type" values carried by the single Reverb event `grup.notification`.
 *
 * These values are the SOURCE OF TRUTH for the Grup realtime contract and are
 * reconciled with RME-Backend `Modules/Grup` (see
 * docs/reconciliation-with-grup-module.md). The hub MUST only emit these four
 * types; anything else is rejected by the client's
 * RealtimeEventProcessor validation (type in: ...).
 */
enum GroupRealtimeEventType: string
{
    /** Group membership / branch roster changed (license-driven on the hub). */
    case MembershipUpdated = 'membership.updated';

    /** Patient record changed in another branch. Signal only — PHI is refetched
     *  on-demand via the REST relay, never inside the event payload. */
    case PatientUpdated = 'patient.updated';

    /** Cross-branch referral created. */
    case ReferralCreated = 'referral.created';

    /** Referral status changed. */
    case ReferralUpdated = 'referral.updated';
}

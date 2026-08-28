<?php

namespace App\Auth\Guards;

use App\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Stateless guard that authenticates a tenant (branch) instance using its
 * service-to-service API token (bearer) for Reverb / broadcasting auth.
 *
 * The token is hashed (sha256) and matched against tenants.api_token_hash, exactly
 * like AuthenticateServiceToken middleware. Suspended/terminated tenants are
 * rejected. This keeps broadcast auth in lock-step with the regular API auth and
 * avoids a second, divergent trust path.
 */
class TenantTokenGuard implements Guard
{
    public function __construct(
        protected Request $request,
    ) {}

    /**
     * The currently authenticated tenant, resolved once per request.
     */
    protected ?Tenant $user = null;

    protected bool $hasUser = false;

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return $this->user() === null;
    }

    public function user(): ?Authenticatable
    {
        if ($this->hasUser) {
            return $this->user;
        }

        $this->hasUser = true;
        $this->user = $this->resolveTenant();

        return $this->user;
    }

    public function id(): string|int|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        // Stateless guard: not used for login flows.
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user instanceof Tenant ? $user : null;
        $this->hasUser = true;

        return $this;
    }

    protected function resolveTenant(): ?Tenant
    {
        $token = $this->request->bearerToken();

        if (empty($token)) {
            return null;
        }

        $hash = hash('sha256', $token);

        /** @var Tenant|null $tenant */
        $tenant = Tenant::where('api_token_hash', $hash)->first();

        if (! $tenant) {
            return null;
        }

        if (in_array($tenant->status, ['suspended', 'terminated'], true)) {
            return null;
        }

        return $tenant;
    }
}

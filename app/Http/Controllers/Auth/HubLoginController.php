<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hub\LoginRequest;
use App\Models\HubAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class HubLoginController extends Controller
{
    public function __construct()
    {
        // Uses the dedicated "hub" guard so the admin session is isolated
        // from the client Sanctum API users ($guard web / provider users).
        $this->middleware('guest:hub')->except('logout');
        $this->middleware('auth:hub')->only('logout');
    }

    public function showLoginForm()
    {
        return view('hub.auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $this->ensureNotRateLimited($request);

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('hub')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records or the account is inactive.'),
            ]);
        }

        /** @var HubAdmin $admin */
        $admin = Auth::guard('hub')->user();

        if (! $admin->is_active) {
            Auth::guard('hub')->logout();

            throw ValidationException::withMessages([
                'email' => __('This account has been disabled.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $request->session()->regenerate();

        return redirect()->intended(route('hub.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('hub')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('hub.login');
    }

    protected function ensureNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => $seconds]),
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return 'hub-login|'.($request->ip() ?? '').'|'.strtolower((string) $request->input('email'));
    }
}

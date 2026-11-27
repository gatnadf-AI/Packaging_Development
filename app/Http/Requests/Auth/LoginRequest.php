<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Access\MatrixResolver;
use App\Models\User;
use App\Rules\CorporateEmailDomain;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TSD-1105 with MSG-001 — five tries a minute, then ten minutes of cold.
 *
 * Two counters, because they answer different questions. The first is the
 * framework's own per-minute limiter, which stops a fast guess. The second
 * starts when the first is exhausted and holds the door for ten minutes, which
 * is what makes a slow, spread-out attack on one address expensive.
 *
 * Both are keyed on the address AND the caller's ip, so a rejected attempt
 * against an address that does not exist cannot be told apart from one against
 * an address that does. The response is the same sentence either way (MSG-001
 * discipline, extended to the lockout by TSD-1104).
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 10;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The corporate domain is checked before the password is looked at, because
     * V-101's own sentence is about the address rather than the credentials
     * ("Akun Anda tidak terdaftar di domain ini") and it is the more useful
     * answer for the person who typed their personal address into a system they
     * were given an account in. The rest of this form keeps MSG-001 discipline:
     * inside the corporate domain, an unknown address and a wrong password are
     * the same sentence, so the screen is not a directory.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', new CorporateEmailDomain()],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // V-101: the address is lowercased and trimmed before the lookup, so
        // "RnD.Staf1@PPM.TEST " and the stored value are one identity. The
        // password is taken exactly as typed; trimming it would silently widen
        // the accepted set.
        $credentials = [
            'email' => $this->normalizedEmail(),
            'password' => (string) $this->input('password'),
        ];

        if (! Auth::attempt($credentials, $this->rememberRequested())) {
            RateLimiter::hit($this->throttleKey());

            if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
                // The minute window is spent; hand the caller to the long lock.
                RateLimiter::hit($this->lockoutKey(), self::LOCKOUT_MINUTES * 60);
            }

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // FSD-202 — a deactivated account is refused with the same sentence as a
        // wrong password, even though here the password was right. The counter
        // is still hit, so this path cannot be used to probe at speed either.
        if (! Auth::user()->is_active) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->lockoutKey());
    }

    /**
     * "Ingat saya" honoured or refused (V-101 field note: privileged sessions
     * never persist).
     *
     * The checkbox cannot be hidden before login because the role is not known
     * until the password is, so the refusal happens here instead: a ticked box
     * on an account that administers access produces a session that ends with
     * the browser, whatever the form asked for. The answer is the same either
     * way, so this cannot be used to learn which address holds which role.
     *
     * The feature is 'admin.access'. The FSD's field note calls it admin.manage,
     * which is not a key in the §6 grid it also signs off; the grid is what the
     * database holds, so the grid wins and the wording difference is reported.
     */
    private function rememberRequested(): bool
    {
        if (! $this->boolean('remember')) {
            return false;
        }

        $user = User::query()->where('email', $this->normalizedEmail())->first();

        if ($user === null) {
            // The attempt is going to fail anyway; the flag is irrelevant.
            return true;
        }

        return ! app(MatrixResolver::class)->forUser($user)->canAct('admin.access');
    }

    private function normalizedEmail(): string
    {
        return Str::lower(trim((string) $this->input('email')));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $lockedSeconds = RateLimiter::availableIn($this->lockoutKey());

        if ($lockedSeconds > 0) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'email' => trans('auth.locked', ['minutes' => (int) ceil($lockedSeconds / 60)]),
            ]);
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->identityKey());
    }

    public function lockoutKey(): string
    {
        return 'login-lockout|'.Str::transliterate($this->identityKey());
    }

    private function identityKey(): string
    {
        return Str::lower(trim((string) $this->input('email'))).'|'.$this->ip();
    }
}

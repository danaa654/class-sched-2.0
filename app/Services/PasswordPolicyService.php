<?php

namespace App\Services;

use Illuminate\Validation\Rules\Password;

/**
 * SECURITY / PASSWORD POLICY — the single place that turns the
 * Settings > Security values (security.min_password_length,
 * security.require_number, security.require_symbol) into an actual
 * Laravel validation rule chain.
 *
 * Every place a password is set calls PasswordPolicyService::rules()
 * instead of hand-writing 'min:8' or similar — that was the original
 * problem: UsersController::rules(), UsersController::updateAccount(),
 * and NewPasswordController::store() each had their own inline rule
 * that could silently drift out of sync. Now all three read the same
 * settings through the same method, so the policy is enforced
 * identically everywhere a password is created or changed, and
 * changing it in Settings changes it everywhere at once.
 *
 * password_expiry_days is deliberately NOT part of this class — that
 * isn't a validation rule on a submitted password, it's a check
 * against an existing password's age. See
 * App\Http\Middleware\EnsurePasswordIsCurrent for that half of the
 * policy.
 */
class PasswordPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The enforced password rule chain, ready to drop into a
     * validate() call: ['password' => [...PasswordPolicyService::rules()]].
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        $minLength = (int) $this->settings->get('security.min_password_length', 8);
        $requireUppercase = (bool) $this->settings->get('security.require_uppercase', false);
        $requireNumber = (bool) $this->settings->get('security.require_number', false);
        $requireSymbol = (bool) $this->settings->get('security.require_symbol', false);

        $rule = Password::min(max(1, $minLength));

        // Laravel's mixedCase() requires BOTH an uppercase and a
        // lowercase letter (there's no upper-only primitive) — that's
        // the accepted trade-off for "at least one uppercase letter".
        if ($requireUppercase) {
            $rule = $rule->mixedCase();
        }

        if ($requireNumber) {
            $rule = $rule->numbers();
        }

        if ($requireSymbol) {
            $rule = $rule->symbols();
        }

        return [$rule];
    }

    /**
     * A short, human-readable statement of the current policy, for
     * display under a password field (e.g. "At least 10 characters,
     * including a number and a symbol.").
     */
    public function description(): string
    {
        $minLength = (int) $this->settings->get('security.min_password_length', 8);
        $requireUppercase = (bool) $this->settings->get('security.require_uppercase', false);
        $requireNumber = (bool) $this->settings->get('security.require_number', false);
        $requireSymbol = (bool) $this->settings->get('security.require_symbol', false);

        $requirements = array_filter([
            $requireUppercase ? 'an uppercase letter' : null,
            $requireNumber ? 'a number' : null,
            $requireSymbol ? 'a symbol' : null,
        ]);

        $suffix = $requirements === [] ? '' : ', including '.implode(' and ', $requirements);

        return "At least {$minLength} characters{$suffix}.";
    }

    /**
     * Structured version of the same values description() prints, for
     * the read-only Settings > Security tab to render as a checklist
     * instead of parsing the sentence back apart.
     *
     * @return array{minLength: int, requireUppercase: bool, requireNumber: bool, requireSymbol: bool, expiryDays: int}
     */
    public function requirements(): array
    {
        return [
            'minLength' => (int) $this->settings->get('security.min_password_length', 8),
            'requireUppercase' => (bool) $this->settings->get('security.require_uppercase', false),
            'requireNumber' => (bool) $this->settings->get('security.require_number', false),
            'requireSymbol' => (bool) $this->settings->get('security.require_symbol', false),
            'expiryDays' => (int) $this->settings->get('security.password_expiry_days', 0),
        ];
    }

    /**
     * Whether a given user's password has expired under
     * security.password_expiry_days (0 = expiry disabled). Read by
     * EnsurePasswordIsCurrent on every request and used by the Users
     * page to show an "Expired" badge.
     */
    public function isExpired(?\Illuminate\Support\Carbon $passwordChangedAt): bool
    {
        $expiryDays = (int) $this->settings->get('security.password_expiry_days', 0);

        if ($expiryDays <= 0 || $passwordChangedAt === null) {
            return false;
        }

        return $passwordChangedAt->addDays($expiryDays)->isPast();
    }
}
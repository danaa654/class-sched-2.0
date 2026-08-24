<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

/**
 * Regression coverage for the "Forgot Password" success indicator bug:
 * the "Forgot password?" link only flips AuthFlipCard client-side
 * (the browser stays on /login), so PasswordResetLinkController::store()
 * previously used back(), which redirected to /login's referer and
 * silently lost the flashed 'status' message instead of showing it on
 * the Forgot Password face. It must now always redirect to
 * password.request regardless of which page the request came from.
 */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_reset_link_request_redirects_to_forgot_password_route_with_status(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ccs@classly.local']);

        $response = $this
            ->from('/login') // simulate the client-side flip: browser never actually visited /forgot-password
            ->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_request_for_unknown_email_still_redirects_to_forgot_password_route_with_status(): void
    {
        // No account exists for this email — the app deliberately shows
        // the same generic "check your inbox" status either way so the
        // form can't be used to enumerate registered accounts, and that
        // status must still land on the Forgot Password face.
        $response = $this
            ->from('/login')
            ->post('/forgot-password', ['email' => 'nobody@classly.local']);

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHas('status');
    }

    public function test_forgot_password_page_renders_the_flashed_status_as_a_prop(): void
    {
        $response = $this->withSession(['status' => __('passwords.sent')])->get('/forgot-password');

        $response->assertInertia(fn ($page) => $page
            ->where('status', __('passwords.sent'))
        );
    }
}
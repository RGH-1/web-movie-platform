<?php

namespace Tests\Feature;

use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class OAuthRedirectTest extends TestCase
{
    public function test_unsupported_provider_is_rejected(): void
    {
        $this->get('/auth/unknown/redirect')
            ->assertNotFound();
    }

    public function test_unconfigured_provider_is_rejected(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);

        $this->get('/auth/google/redirect')
            ->assertStatus(503);
    }

    public function test_configured_provider_starts_redirect(): void
    {
        config()->set('services.github.client_id', 'test-client');
        config()->set('services.github.client_secret', 'test-secret');

        Socialite::fake('github');

        $this->get('/auth/github/redirect')
            ->assertRedirect();
    }
}

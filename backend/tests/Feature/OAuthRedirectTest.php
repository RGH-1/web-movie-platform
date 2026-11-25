<?php

namespace Tests\Feature;

use Tests\TestCase;

class OAuthRedirectTest extends TestCase
{
    public function test_unsupported_provider_is_rejected_on_both_routes(): void
    {
        $this->get('/auth/unknown/redirect')->assertNotFound();
        $this->get('/auth/unknown/callback')->assertNotFound();
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

        $response = $this->get('/auth/github/redirect')
            ->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertSame('github.com', parse_url($location, PHP_URL_HOST));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('test-client', $query['client_id'] ?? null);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertStringNotContainsString('test-secret', $location);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_has_security_headers_and_nonce_based_csp(): void
    {
        $response = $this->get('/')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-([A-Za-z0-9]+)'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline' https://www.google.com", $csp);
        $this->assertStringNotContainsString('script-src \'self\' \'unsafe-inline\'', $csp);

        // встроенный скрипт страницы получает nonce из заголовка, иначе браузер его не выполнит
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $response->getContent());
    }

    public function test_nonce_is_new_for_every_request(): void
    {
        $a = $this->get('/')->headers->get('Content-Security-Policy');
        $b = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotSame($a, $b);
    }

    public function test_api_responses_also_have_headers(): void
    {
        $this->getJson('/api/v1/comments')->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_api_docs_are_excluded_from_csp(): void
    {
        // Stoplight Elements грузится с unpkg и использует встроенные скрипты
        $response = $this->get('/docs/api')->assertOk()->assertHeader('X-Frame-Options', 'DENY');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_hsts_only_over_https(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_full_email_is_never_exposed(): void
    {
        Comment::create(['user_name' => 'anna_dev', 'email' => 'anna.secret@example.com', 'text' => 'hi']);

        $response = $this->getJson('/comments')->assertOk()
            ->assertJsonPath('data.0.email', 'a***@example.com');

        $this->assertStringNotContainsString('anna.secret', $response->getContent());
        $this->assertArrayNotHasKey('email', Comment::first()->toArray());
    }
}

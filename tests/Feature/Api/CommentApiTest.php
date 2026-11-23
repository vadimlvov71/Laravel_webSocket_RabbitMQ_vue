<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Events\CommentCreated;
use App\Models\ApiClient;
use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use Tests\TestCase;

/**
 * REST API v1. Каждый ответ, кроме специально оговорённых, сверяется
 * со спецификацией OpenAPI, которую Scramble генерирует из кода (/docs/api.json):
 * если код и документация разойдутся, тест упадёт.
 */
class CommentApiTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $spec = null;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([CommentCreated::class]);
    }

    public function test_requests_without_valid_key_are_401(): void
    {
        $this->assertContract($this->getJson('/api/v1/comments')->assertUnauthorized(), '/v1/comments', 'get');

        $this->getJson('/api/v1/comments', ['Authorization' => 'Bearer 1|wrong'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_api_answers_json_even_without_accept_header(): void
    {
        // без shouldRenderJsonWhen гость получил бы редирект на несуществующий login (500)
        $this->get('/api/v1/comments')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_list_returns_paginated_roots_with_nested_replies(): void
    {
        $root = $this->comment('anna_dev');
        $reply = $this->comment('bob', $root);
        $this->comment('carol', $reply);

        $response = $this->api()->getJson('/api/v1/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.replies.0.user_name', 'bob')
            ->assertJsonPath('data.0.replies.0.replies.0.user_name', 'carol')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1);

        $this->assertContract($response, '/v1/comments', 'get');
    }

    public function test_show_returns_comment_subtree(): void
    {
        $root = $this->comment('anna_dev');
        $reply = $this->comment('bob', $root);
        $this->comment('carol', $reply);
        $this->comment('dmitry', $root); // соседняя ветка: в поддерево bob не входит

        $response = $this->api()->getJson("/api/v1/comments/{$reply->id}")
            ->assertOk()
            ->assertJsonPath('data.user_name', 'bob')
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.user_name', 'carol');

        $this->assertContract($response, '/v1/comments/{comment}', 'get');
    }

    public function test_unknown_comment_is_404_without_internal_details(): void
    {
        $response = $this->api()->getJson('/api/v1/comments/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        $this->assertContract($response, '/v1/comments/{comment}', 'get');
    }

    public function test_create_without_captcha_returns_201_and_broadcasts(): void
    {
        $parent = $this->comment('anna_dev');

        $response = $this->api()->postJson('/api/v1/comments', [
            'user_name' => 'api_bot',
            'email' => 'bot@example.com',
            'text' => 'Hello from <strong>API</strong>',
            'parent_id' => $parent->id,
        ])->assertCreated()
            ->assertJsonPath('data.user_name', 'api_bot')
            ->assertJsonPath('data.text', 'Hello from <strong>API</strong>');

        $this->assertContract($response, '/v1/comments', 'post');
        $this->assertSame($parent->id, Comment::where('user_name', 'api_bot')->value('parent_id'));
        // комментарий из API появляется на сайте в реальном времени, как и с формы
        Event::assertDispatched(CommentCreated::class);
    }

    public function test_create_with_image_attachment(): void
    {
        Storage::fake('public');

        $this->api()->post('/api/v1/comments', [
            'user_name' => 'api_bot',
            'email' => 'bot@example.com',
            'text' => 'Look',
            'attachment' => UploadedFile::fake()->image('photo.jpg', 640, 480),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachment.type', 'image')
            ->assertJsonPath('data.attachment.name', 'photo.jpg');
    }

    public function test_create_validation_errors_are_422(): void
    {
        $response = $this->api()->postJson('/api/v1/comments', ['user_name' => 'x', 'email' => 'bad'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_name', 'email', 'text'])
            ->assertJsonMissingValidationErrors('captcha');

        $this->assertContract($response, '/v1/comments', 'post');
    }

    public function test_read_only_key_cannot_create(): void
    {
        $response = $this->api([ApiAbility::CommentsRead->value])
            ->postJson('/api/v1/comments', ['user_name' => 'api_bot', 'email' => 'bot@example.com', 'text' => 'hi'])
            ->assertForbidden();

        $this->assertContract($response, '/v1/comments', 'post');
        $this->assertSame(0, Comment::count());
    }

    public function test_write_only_key_cannot_read(): void
    {
        $this->api([ApiAbility::CommentsWrite->value])->getJson('/api/v1/comments')->assertForbidden();
    }

    public function test_create_is_rate_limited_per_key(): void
    {
        $api = $this->api();
        for ($i = 0; $i < 20; $i++) {
            $api->postJson('/api/v1/comments', [])->assertUnprocessable();
        }

        $response = $api->postJson('/api/v1/comments', [])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertContract($response, '/v1/comments', 'post');

        // лимит у каждого ключа свой
        $this->api()->postJson('/api/v1/comments', [])->assertUnprocessable();
    }

    public function test_search_returns_hits(): void
    {
        config(['services.elasticsearch.enabled' => true, 'services.elasticsearch.host' => 'http://es.test:9200']);
        Http::fake(['es.test:9200/*' => Http::response(['hits' => ['hits' => [[
            '_source' => ['id' => 5, 'parent_id' => null, 'root_id' => null, 'user_name' => 'anna_dev', 'created_at' => now()->toIso8601String()],
            'highlight' => ['text' => ['<mark>queue</mark>']],
        ]]]])]);

        $response = $this->api()->getJson('/api/v1/comments/search?q=queue')
            ->assertOk()
            ->assertJsonPath('data.0.snippet', '<mark>queue</mark>');

        $this->assertContract($response, '/v1/comments/search', 'get');
    }

    public function test_search_unavailable_is_503(): void
    {
        // в phpunit.xml поиск выключен
        $response = $this->api()->getJson('/api/v1/comments/search?q=queue')->assertStatus(503);

        $this->assertContract($response, '/v1/comments/search', 'get');
    }

    public function test_revoked_key_stops_working(): void
    {
        $client = ApiClient::create(['name' => 'partner']);
        $token = $client->createToken('default', ApiAbility::values())->plainTextToken;
        $this->getJson('/api/v1/comments', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->artisan('api:client-revoke', ['client' => $client->id])->assertSuccessful();

        // охранник Sanctum кэширует пользователя в рамках приложения теста
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/comments', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_client_create_command_issues_working_token(): void
    {
        $this->artisan('api:client-create', ['name' => 'partner', '--ability' => ['comments:read']])
            ->expectsOutputToContain('abilities: comments:read')
            ->assertSuccessful();

        $token = ApiClient::where('name', 'partner')->sole()->tokens()->sole();
        $this->assertSame(['comments:read'], $token->abilities);
    }

    public function test_client_create_rejects_unknown_ability(): void
    {
        $this->artisan('api:client-create', ['name' => 'partner', '--ability' => ['comments:delete']])
            ->expectsOutputToContain('Unknown ability: comments:delete')
            ->assertFailed();

        $this->assertSame(0, ApiClient::count());
    }

    public function test_docs_are_public_and_describe_security(): void
    {
        $spec = $this->getJson('/docs/api.json')->assertOk();

        $spec->assertJsonPath('info.title', 'Comments API')
            ->assertJsonPath('components.securitySchemes.accessKey.scheme', 'bearer');
        $this->assertArrayHasKey('403', $spec->json('paths./v1/comments.post.responses'));
        $this->assertArrayHasKey('429', $spec->json('paths./v1/comments.post.responses'));
        $this->get('/docs/api')->assertOk();
    }

    /** Проверка по спецификации действительно ловит расхождения, а не пропускает всё. */
    public function test_contract_check_rejects_response_that_does_not_match_spec(): void
    {
        $wrong = response()->json(['data' => [['id' => 'not-a-number', 'user_name' => 'x']]]);

        $this->expectException(\Osteel\OpenApi\Testing\Exceptions\ValidationException::class);
        $this->validator()->validate($wrong, '/v1/comments', 'get');
    }

    /** Клиент запросов с ключом, у которого заданные права (по умолчанию все). */
    private function api(?array $abilities = null): static
    {
        $client = ApiClient::create(['name' => 'test client']);
        $token = $client->createToken('test', $abilities ?? ApiAbility::values())->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function assertContract(TestResponse $response, string $path, string $method): void
    {
        $this->assertTrue($this->validator()->validate($response->baseResponse, $path, $method));
    }

    private function validator(): ValidatorInterface
    {
        // генерация спецификации небыстрая: один раз на весь класс
        self::$spec ??= $this->getJson('/docs/api.json')->assertOk()->getContent();

        return ValidatorBuilder::fromJson(self::$spec)->getValidator();
    }

    private function comment(string $name, ?Comment $parent = null): Comment
    {
        return Comment::create([
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
            'user_name' => $name,
            'email' => "{$name}@example.com",
            'text' => 'text',
        ]);
    }
}

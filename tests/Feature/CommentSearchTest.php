<?php

namespace Tests\Feature;

use App\Jobs\SyncCommentToSearch;
use App\Models\Comment;
use App\Services\CommentSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Поиск через Elasticsearch: запросы к нему подменены Http::fake(),
 * живой кластер для тестов не нужен.
 */
class CommentSearchTest extends TestCase
{
    use RefreshDatabase;

    private const ES = 'http://es.test:9200';

    protected function setUp(): void
    {
        parent::setUp();

        // в phpunit.xml поиск выключен; здесь включаем на фейковый адрес
        config(['services.elasticsearch.enabled' => true, 'services.elasticsearch.host' => self::ES]);
    }

    public function test_search_returns_hits_with_highlighted_snippet(): void
    {
        Http::fake([self::ES.'/comments/_search' => Http::response(['hits' => ['hits' => [
            $this->hit(7, 'anna_dev', ['text.ru' => ['про <mark>очереди</mark>'], 'text' => ['про очереди']]),
            $this->hit(8, 'bob', ['text' => ['<mark>queue</mark> &lt;b&gt;']]),
        ]]])]);

        $this->getJson('/comments/search?q=очередь')
            ->assertOk()
            ->assertJsonPath('data.0.id', 7)
            ->assertJsonPath('data.0.user_name', 'anna_dev')
            // совпадение по морфологии важнее точного: берём подсветку из text.ru
            ->assertJsonPath('data.0.snippet', 'про <mark>очереди</mark>')
            ->assertJsonPath('data.1.snippet', '<mark>queue</mark> &lt;b&gt;');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $body['highlight']['encoder'] === 'html'
                && $body['query']['bool']['should'][0]['multi_match']['query'] === 'очередь'
                && $body['query']['bool']['should'][0]['multi_match']['fuzziness'] === 'AUTO';
        });
    }

    public function test_search_needs_at_least_two_characters(): void
    {
        Http::fake();

        $this->getJson('/comments/search?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
        Http::assertNothingSent();
    }

    public function test_search_is_503_when_elasticsearch_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->getJson('/comments/search?q=test')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Search is temporarily unavailable.');
    }

    public function test_search_is_503_when_elasticsearch_returns_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'index_not_found'], 404)]);

        $this->getJson('/comments/search?q=test')->assertStatus(503);
    }

    public function test_search_is_503_when_disabled(): void
    {
        config(['services.elasticsearch.enabled' => false]);
        Http::fake();

        $this->getJson('/comments/search?q=test')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_document_contains_plain_text_without_markup(): void
    {
        $comment = $this->comment('<strong>Hello</strong> &amp; <a href="https://example.com" title="t">world</a>');

        $this->assertSame('Hello & world', CommentSearch::document($comment)['text']);
    }

    public function test_creating_and_deleting_comment_queues_index_sync(): void
    {
        Queue::fake();

        $comment = $this->comment('text');
        $comment->delete();

        Queue::assertPushedOn('default', SyncCommentToSearch::class, fn ($job) => $job->commentId === $comment->id);
        Queue::assertPushed(SyncCommentToSearch::class, 2);
    }

    public function test_no_sync_jobs_when_search_disabled(): void
    {
        config(['services.elasticsearch.enabled' => false]);
        Queue::fake();

        $this->comment('text');

        Queue::assertNothingPushed();
    }

    public function test_sync_job_indexes_existing_and_deletes_missing_comment(): void
    {
        Queue::fake(); // события модели не должны запускать задачу сами
        Http::fake(['*' => Http::response(['result' => 'ok'])]);
        $comment = $this->comment('<i>indexed</i>');

        (new SyncCommentToSearch($comment->id))->handle(app(CommentSearch::class));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === self::ES."/comments/_doc/{$comment->id}"
            && $r['text'] === 'indexed');

        Http::fake(['*' => Http::response(['result' => 'not_found'], 404)]);
        (new SyncCommentToSearch(999))->handle(app(CommentSearch::class)); // 404 при удалении не ошибка
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::ES.'/comments/_doc/999');
    }

    public function test_reindex_recreates_index_and_bulk_loads_comments(): void
    {
        Queue::fake();
        $this->comment('first');
        $this->comment('second');
        Http::fake(['*' => Http::response(['acknowledged' => true, 'errors' => false])]);

        $this->artisan('comments:reindex')->expectsOutputToContain('Indexed 2 comments.')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::ES.'/comments'
            && isset($r['mappings']['properties']['text']['fields']['ru']));
        Http::assertSent(function (Request $r) {
            $lines = explode("\n", trim($r->body()));

            return $r->url() === self::ES.'/_bulk' && count($lines) === 4
                && $r->hasHeader('Content-Type', 'application/x-ndjson');
        });
    }

    public function test_reindex_fails_when_elasticsearch_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('comments:reindex')->assertFailed();
    }

    private function hit(int $id, string $name, array $highlight): array
    {
        return [
            '_source' => ['id' => $id, 'parent_id' => null, 'root_id' => null, 'user_name' => $name, 'created_at' => now()->toIso8601String()],
            'highlight' => $highlight,
        ];
    }

    private function comment(string $text): Comment
    {
        return Comment::create(['user_name' => 'anna_dev', 'email' => 'a@example.com', 'text' => $text]);
    }
}

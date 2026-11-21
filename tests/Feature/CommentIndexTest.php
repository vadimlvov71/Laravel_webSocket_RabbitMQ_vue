<?php

namespace Tests\Feature;

use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /comments: разбор параметров запроса и кэширование страниц.
 */
class CommentIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Comment::create(['user_name' => 'Alice', 'email' => 'a@example.com', 'text' => 'first']);
        Comment::create(['user_name' => 'Bob', 'email' => 'b@example.com', 'text' => 'second']);
    }

    /**
     * Параметр, переданный массивом или мусором, не должен давать 500:
     * берётся значение по умолчанию.
     */
    #[DataProvider('malformedQueries')]
    public function test_malformed_query_falls_back_to_defaults(string $query): void
    {
        $this->getJson('/comments?'.$query)
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(2, 'data');
    }

    public static function malformedQueries(): array
    {
        return [
            'sort as array' => ['sort[]=x'],
            'dir as array' => ['dir[]=asc'],
            'page as array' => ['page[]=2'],
            'unknown sort' => ['sort=bogus&dir=sideways'],
            'negative page' => ['page=-5'],
            'page not a number' => ['page=abc'],
        ];
    }

    public function test_sort_by_user_name(): void
    {
        $this->getJson('/comments?sort=user_name&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.user_name', 'Alice')
            ->assertJsonPath('data.1.user_name', 'Bob');
    }

    public function test_page_beyond_last_is_empty_and_not_cached(): void
    {
        $this->getJson('/comments?page=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertFalse(Cache::has($this->pageKey(999999)));
    }

    public function test_page_size_comes_from_config(): void
    {
        config(['comments.per_page' => 1]);

        $this->getJson('/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 2);
    }

    /** Смена размера страницы не должна отдавать страницу, закэшированную со старым размером. */
    public function test_changing_page_size_does_not_serve_stale_cache(): void
    {
        config(['comments.per_page' => 1]);
        $this->getJson('/comments')->assertJsonCount(1, 'data');

        config(['comments.per_page' => 25]);
        $this->getJson('/comments')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_existing_page_is_cached(): void
    {
        $this->getJson('/comments')->assertOk();

        $this->assertTrue(Cache::has($this->pageKey(1)));
    }

    /** Ключ страницы по текущей версии: каждый созданный комментарий увеличивает версию. */
    private function pageKey(int $page): string
    {
        return sprintf(
            'comments:v%d:p%d:created_at:desc:%d',
            Cache::get('comments:version'),
            config('comments.per_page'),
            $page
        );
    }
}

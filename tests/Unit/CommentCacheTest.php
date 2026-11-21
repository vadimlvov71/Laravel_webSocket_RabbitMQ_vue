<?php

namespace Tests\Unit;

use App\Services\CommentCache;
use Tests\TestCase;

class CommentCacheTest extends TestCase
{
    public function test_page_is_built_once_and_then_served_from_cache(): void
    {
        $cache = app(CommentCache::class);
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return ['data' => [], 'meta' => ['total' => $calls]];
        };

        $cache->remember('created_at', 'desc', 1, $build);
        $result = $cache->remember('created_at', 'desc', 1, $build);

        $this->assertSame(1, $calls);
        $this->assertSame(1, $result['meta']['total']);
    }

    public function test_different_pages_have_different_keys(): void
    {
        $cache = app(CommentCache::class);

        $first = $cache->remember('created_at', 'desc', 1, fn () => ['data' => [1]]);
        $second = $cache->remember('created_at', 'desc', 2, fn () => ['data' => [2]]);
        $sorted = $cache->remember('user_name', 'asc', 1, fn () => ['data' => ['by name']]);

        $this->assertSame(['data' => [1]], $first);
        $this->assertSame(['data' => [2]], $second);
        $this->assertSame(['data' => ['by name']], $sorted);
    }

    public function test_flush_makes_cached_pages_stale(): void
    {
        $cache = app(CommentCache::class);

        $cache->remember('created_at', 'desc', 1, fn () => ['data' => ['old']]);
        $cache->flush();
        $result = $cache->remember('created_at', 'desc', 1, fn () => ['data' => ['new']]);

        $this->assertSame(['data' => ['new']], $result);
    }

    public function test_empty_page_beyond_first_is_not_cached(): void
    {
        $cache = app(CommentCache::class);
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return ['data' => []];
        };

        $cache->remember('created_at', 'desc', 999999, $build);
        $cache->remember('created_at', 'desc', 999999, $build);

        $this->assertSame(2, $calls);
    }

    public function test_empty_first_page_is_cached(): void
    {
        $cache = app(CommentCache::class);
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return ['data' => []];
        };

        $cache->remember('created_at', 'desc', 1, $build);
        $cache->remember('created_at', 'desc', 1, $build);

        $this->assertSame(1, $calls);
    }

    public function test_flush_works_before_anything_was_cached(): void
    {
        $cache = app(CommentCache::class);

        $cache->flush();
        $result = $cache->remember('created_at', 'desc', 1, fn () => ['data' => ['fresh']]);

        $this->assertSame(['data' => ['fresh']], $result);
    }
}

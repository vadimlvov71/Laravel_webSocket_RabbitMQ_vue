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

        $first = $cache->remember('created_at', 'desc', 1, fn () => ['page' => 1]);
        $second = $cache->remember('created_at', 'desc', 2, fn () => ['page' => 2]);
        $sorted = $cache->remember('user_name', 'asc', 1, fn () => ['page' => 'by name']);

        $this->assertSame(['page' => 1], $first);
        $this->assertSame(['page' => 2], $second);
        $this->assertSame(['page' => 'by name'], $sorted);
    }

    public function test_flush_makes_cached_pages_stale(): void
    {
        $cache = app(CommentCache::class);

        $cache->remember('created_at', 'desc', 1, fn () => ['v' => 'old']);
        $cache->flush();
        $result = $cache->remember('created_at', 'desc', 1, fn () => ['v' => 'new']);

        $this->assertSame(['v' => 'new'], $result);
    }

    public function test_flush_works_before_anything_was_cached(): void
    {
        $cache = app(CommentCache::class);

        $cache->flush();
        $result = $cache->remember('created_at', 'desc', 1, fn () => ['v' => 'fresh']);

        $this->assertSame(['v' => 'fresh'], $result);
    }
}

<?php

namespace App\Services;

use App\Repositories\CommentRepository;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Кэш страниц списка комментариев.
 *
 * Сброс через версию в ключе, а не удаление ключей: страниц много (сортировка,
 * направление, номер), перебирать их не нужно, а теги поддерживают не все драйверы.
 * Старые версии сами истекают по TTL.
 */
class CommentCache
{
    private const VERSION_KEY = 'comments:version';

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * Пустые страницы после первой не кэшируются: номер страницы задаёт клиент,
     * и перебором ?page=1..N можно было бы забить кэш страницами за пределами списка.
     *
     * @template TPage of array{data: array<int, mixed>}
     *
     * @param  Closure(): TPage  $build  строит страницу, если её нет в кэше
     * @return TPage
     */
    public function remember(string $sort, string $dir, int $page, Closure $build): array
    {
        // размер страницы в ключе: при его смене в конфиге старые страницы не отдаются
        $key = sprintf(
            'comments:v%d:p%d:%s:%s:%d',
            $this->version(),
            CommentRepository::perPage(),
            $sort,
            $dir,
            $page
        );

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $build();

        if ($page === 1 || $result['data'] !== []) {
            $this->cache->put($key, $result, (int) config('comments.cache_ttl'));
        }

        return $result;
    }

    public function flush(): void
    {
        // add() не перезапишет версию, если она уже есть; increment() атомарен в Redis
        $this->cache->add(self::VERSION_KEY, 1);
        $this->cache->increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) $this->cache->rememberForever(self::VERSION_KEY, fn () => 1);
    }
}

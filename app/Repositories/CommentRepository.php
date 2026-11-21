<?php

namespace App\Repositories;

use App\Enums\CommentSort;
use App\Models\Comment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Единственное место, которое знает, как комментарии читаются из базы.
 *
 * Контроллер и кэш работают с готовыми данными и не строят запросы сами.
 * Интерфейса у класса нет намеренно (KISS): Laravel создаёт его автоматически,
 * а подмена реализации в проекте не планируется.
 */
class CommentRepository
{
    /**
     * Страница корневых комментариев (parent_id IS NULL).
     *
     * Второй ключ сортировки (id) делает порядок стабильным, когда у нескольких
     * комментариев совпадает значение основного поля, например одинаковое имя.
     * Поле сортировки приходит как enum, поэтому произвольная строка в запрос
     * попасть не может.
     */
    public function paginateRoots(CommentSort $sort, string $dir, int $page): LengthAwarePaginator
    {
        return Comment::query()
            ->whereNull('parent_id')
            ->orderBy($sort->value, $dir)
            ->orderBy('id', $dir)
            ->paginate(self::perPage(), ['*'], 'page', $page);
    }

    /**
     * Все ответы любой глубины для указанных корневых комментариев, сгруппированные
     * по parent_id. Благодаря колонке root_id хватает одного запроса, и дерево
     * собирается в памяти без N+1.
     *
     * @param  Collection<int, int>  $rootIds
     * @return Collection<int, Collection<int, Comment>>
     */
    public function repliesGroupedByParent(Collection $rootIds): Collection
    {
        return Comment::query()
            ->whereIn('root_id', $rootIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');
    }

    /** Размер страницы задаётся только в config/comments.php. */
    public static function perPage(): int
    {
        return (int) config('comments.per_page');
    }

    /**
     * Страница, на которую paginateRoots() поставит корневой комментарий.
     * Считаем корни, стоящие раньше него при том же порядке (поле, затем id).
     */
    public function pageOfRoot(Comment $root, CommentSort $sort, string $dir): int
    {
        $column = $sort->value;
        $value = $root->getRawOriginal($column);
        $before = $dir === 'desc' ? '>' : '<';

        $ahead = Comment::query()
            ->whereNull('parent_id')
            ->where(fn ($q) => $q
                ->where($column, $before, $value)
                ->orWhere(fn ($q) => $q->where($column, $value)->where('id', $before, $root->id)))
            ->count();

        return intdiv($ahead, self::perPage()) + 1;
    }

    public function findOrFail(int $id): Comment
    {
        return Comment::findOrFail($id);
    }

    /**
     * Создаёт комментарий. Корневой идентификатор ветки (root_id) вычисляется
     * здесь, чтобы вызывающий код не знал про устройство дерева.
     *
     * @param  array{user_name: string, email: string, home_page: ?string, text: string, attachment_path?: string, attachment_type?: string, attachment_name?: string}  $data
     */
    public function create(array $data, ?Comment $parent): Comment
    {
        return Comment::create($data + [
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
        ]);
    }
}

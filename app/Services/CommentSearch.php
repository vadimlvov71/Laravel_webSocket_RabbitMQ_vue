<?php

namespace App\Services;

use App\Exceptions\SearchUnavailableException;
use App\Models\Comment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Полнотекстовый поиск по комментариям в Elasticsearch через его REST API.
 *
 * Отдельный клиент не нужен: используется пять запросов (создать индекс,
 * записать и удалить документ, массовая загрузка, поиск), а Http в тестах
 * подменяется через Http::fake().
 *
 * В индексе лежит текст без HTML-тегов. Подсветка отдаётся с encoder=html:
 * Elasticsearch экранирует текст отрывка и вставляет только наши <mark>,
 * поэтому отрывок можно выводить через v-html.
 */
class CommentSearch
{
    public const RESULTS = 10;

    /**
     * Поля text и user_name с подполями под русскую и английскую морфологию:
     * «комментарии» находит «комментарий», «replies» находит «reply».
     */
    private const MAPPING = [
        'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
        'mappings' => [
            'properties' => [
                'id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer'],
                'root_id' => ['type' => 'integer'],
                'user_name' => [
                    'type' => 'text',
                    'fields' => ['keyword' => ['type' => 'keyword']],
                ],
                'text' => [
                    'type' => 'text',
                    'fields' => [
                        'ru' => ['type' => 'text', 'analyzer' => 'russian'],
                        'en' => ['type' => 'text', 'analyzer' => 'english'],
                    ],
                ],
                'created_at' => ['type' => 'date'],
            ],
        ],
    ];

    public function enabled(): bool
    {
        return (bool) config('services.elasticsearch.enabled');
    }

    /** Удаляет индекс, если он есть, и создаёт заново с актуальной схемой. */
    public function recreateIndex(): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete($this->indexName()), allow404: true);
        $this->send(fn (PendingRequest $http) => $http->put($this->indexName(), self::MAPPING));
    }

    public function index(Comment $comment): void
    {
        $this->send(fn (PendingRequest $http) => $http->put(
            "{$this->indexName()}/_doc/{$comment->id}",
            self::document($comment),
        ));
    }

    /** Удалённого документа может уже не быть: это не ошибка. */
    public function delete(int $id): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete("{$this->indexName()}/_doc/{$id}"), allow404: true);
    }

    /**
     * Массовая загрузка (_bulk, NDJSON): пара строк «действие + документ» на комментарий.
     *
     * @param  iterable<Comment>  $comments
     */
    public function bulk(iterable $comments): int
    {
        $lines = [];
        foreach ($comments as $comment) {
            $lines[] = json_encode(['index' => ['_index' => $this->indexName(), '_id' => $comment->id]]);
            $lines[] = json_encode(self::document($comment), JSON_UNESCAPED_UNICODE);
        }

        if ($lines === []) {
            return 0;
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withBody(implode("\n", $lines)."\n", 'application/x-ndjson')
            ->post('_bulk'));

        if ($response->json('errors')) {
            throw new SearchUnavailableException('Bulk indexing reported errors');
        }

        return intdiv(count($lines), 2);
    }

    /**
     * До RESULTS лучших совпадений. snippet: HTML-отрывок, экранированный
     * Elasticsearch, из тегов в нём только <mark>.
     *
     * @return list<array{id: int, parent_id: ?int, root_id: ?int, user_name: string, created_at: string, snippet: string}>
     */
    public function search(string $query): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post("{$this->indexName()}/_search", [
            'size' => self::RESULTS,
            '_source' => ['id', 'parent_id', 'root_id', 'user_name', 'created_at'],
            'query' => [
                'bool' => [
                    'should' => [
                        // целые слова: морфология и опечатки («коментарий»)
                        [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => ['user_name^3', 'text^2', 'text.ru', 'text.en'],
                                'fuzziness' => 'AUTO',
                            ],
                        ],
                        // ввод «на лету»: последнее слово может быть недописано
                        [
                            'multi_match' => [
                                'query' => $query,
                                'type' => 'phrase_prefix',
                                'fields' => ['user_name^3', 'text^2'],
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            'highlight' => [
                'encoder' => 'html',
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields' => [
                    // no_match_size: если совпало только имя, всё равно показать начало текста
                    'text' => ['fragment_size' => 140, 'number_of_fragments' => 1, 'no_match_size' => 140],
                    'text.ru' => ['fragment_size' => 140, 'number_of_fragments' => 1],
                    'text.en' => ['fragment_size' => 140, 'number_of_fragments' => 1],
                ],
            ],
        ]));

        return array_map(fn (array $hit) => [
            'id' => $hit['_source']['id'],
            'parent_id' => $hit['_source']['parent_id'],
            'root_id' => $hit['_source']['root_id'],
            'user_name' => $hit['_source']['user_name'],
            'created_at' => $hit['_source']['created_at'],
            // совпадение по морфологии подсвечено в подполе, по точному слову в text
            'snippet' => $hit['highlight']['text.ru'][0]
                ?? $hit['highlight']['text.en'][0]
                ?? $hit['highlight']['text'][0]
                ?? '',
        ], $response->json('hits.hits', []));
    }

    /** @return array{id: int, parent_id: ?int, root_id: ?int, user_name: string, text: string, created_at: string} */
    public static function document(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'root_id' => $comment->root_id,
            'user_name' => $comment->user_name,
            // в БД очищенный HTML; для поиска нужен только текст
            'text' => trim(html_entity_decode(strip_tags($comment->text), ENT_QUOTES | ENT_HTML5)),
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }

    private function indexName(): string
    {
        return config('services.elasticsearch.index');
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws SearchUnavailableException
     */
    private function send(callable $request, bool $allow404 = false): Response
    {
        if (! $this->enabled()) {
            throw new SearchUnavailableException('Search is disabled');
        }

        try {
            $response = $request(Http::baseUrl(config('services.elasticsearch.host'))
                ->timeout(config('services.elasticsearch.timeout'))
                ->acceptJson());
        } catch (ConnectionException $e) {
            throw new SearchUnavailableException('Elasticsearch is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed() && ! ($allow404 && $response->notFound())) {
            throw new SearchUnavailableException("Elasticsearch responded {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        return $response;
    }
}

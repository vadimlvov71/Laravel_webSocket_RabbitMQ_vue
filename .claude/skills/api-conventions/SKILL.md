---
name: api-conventions
description: Соглашения REST API проекта комментариев (/api/v1, ключи Sanctum, OpenAPI через Scramble, контрактные тесты). Использовать при любом изменении routes/api.php, app/Http/Controllers/Api, запросов и ресурсов, которые отдаёт API, а также при вопросах о ключах доступа и документации API.
---

# Соглашения REST API

Общие правила слоёв (контроллер → сервис → репозиторий) в `laravel-conventions`. Здесь только то, что касается API.

## Устройство

| Что | Где |
|---|---|
| Маршруты | `routes/api.php`, префикс `/api/v1`, имена `api.v1.*` |
| Контроллеры | `app/Http/Controllers/Api/V1/*`, без логики: вызывают `CommentService` / `CommentSearch` |
| Запросы | общие с сайтом (`ListCommentsRequest`, `SearchCommentsRequest`); если правила отличаются — наследник в `app/Http/Requests/Api/` (пример: `StoreCommentApiRequest` без `captcha`) |
| Ответы | `JsonResource` (`CommentResource`), списки — `Resource::collection($paginator)` (стандартные `data`, `links`, `meta` Laravel) |
| Права ключа | `App\Enums\ApiAbility`: `comments:read`, `comments:write` |
| Владельцы ключей | `App\Models\ApiClient` (не `User`: логина на сайте нет) |
| Документация | Scramble: `/docs/api` (UI), `/docs/api.json` (спецификация), `config/scramble.php`, `AppServiceProvider::configureApiDocs()` |
| Ответы 403/429 в документации | `App\OpenApi\ApiErrorResponses`: выводятся из middleware маршрута (`ability:*`, `throttle:*`) |

## Правила

1. **Версия в URL.** Ломающее изменение (удалить или переименовать поле, сменить тип, сделать поле обязательным) — только в `/api/v2`, v1 продолжает работать. Добавить необязательное поле в ответ или запрос — можно в v1.
2. **Каждый маршрут** под `auth:sanctum` + `throttle:api` и с проверкой права `ability:<право>`. Запись дополнительно `throttle:api-write`. Новое право — сначала case в `ApiAbility`.
3. **Логика только в сервисах.** API и сайт используют одни и те же методы `CommentService`; в контроллере API нет запросов к БД и условий бизнес-логики.
4. **Ошибки всегда JSON** `{"message": "..."}` (+ `errors` для 422). Коды: 401 нет/неверный ключ, 403 нет права, 404, 422, 429 (+ `Retry-After`), 503 поиск недоступен. Внутренние детали (имена классов, SQL) в тексте ошибки недопустимы: см. обработчики в `bootstrap/app.php`.
5. **reCAPTCHA в API нет**: клиента подтверждает ключ. Все остальные проверки формы (`user_name`, разметка текста, вложения) те же, что на сайте.
6. **Лимиты считаются по ключу**, а не по IP (`AppServiceProvider::configureApiRateLimits()`).
7. **Не отдавать лишнего.** Поле в ресурсе — значит оно публичное для всех клиентов с ключом.

## Документация (Scramble)

Документация генерируется из кода, отдельно её не пишем. Чтобы она была точной:

- **PHPDoc методов контроллера API — на английском**: первая строка = summary, дальше description. Упомянуть требуемое право и особенности. Пояснения для разработчиков (на русском) — обычными `//` комментариями, не PHPDoc: PHPDoc класса запроса/ресурса тоже попадает в публичную документацию.
- **Параметры без правил валидации** (как `sort`/`dir`/`page`) описывать `#[QueryParameter(...)]`.
- **Ответ-массив** (не ресурс) описывать `@response array{...}`; дополнительный код ответа внутри метода — PHPDoc над `return` с `@status` и `@body`.
- **Невозможный ответ** убирать `#[IgnoreResponse(422)]` (например, FormRequest без правил).
- После изменения открыть `/docs/api` и проверить, что параметры, тело и ответы совпадают с ожиданием.

## Тесты

- `tests/Feature/Api/*`. Каждый ответ сверяется со спецификацией: `assertContract($response, '/v1/comments/{comment}', 'get')` (путь — шаблон из OpenAPI, без `/api`). Если код и документация разошлись, тест падает: исправлять документацию (PHPDoc/атрибуты), а не отключать проверку.
- Для нового эндпоинта минимум: успех, 401, 403 (чужое право), 422 (если есть ввод), 404 (если есть `{id}`), контракт для каждого.
- Ключ в тесте — `$this->api([...abilities])`; Elasticsearch — `Http::fake()`.

## Ключи доступа

```bash
php artisan api:client-create "Partner" --contact=dev@partner.example --ability=comments:read
php artisan api:client-list
php artisan api:client-revoke <client id>
```

Ключ показывается один раз (в БД только хэш). Пример запроса:

```bash
curl -H "Authorization: Bearer <token>" "http://localhost:8086/api/v1/comments?sort=created_at&dir=desc"
```

## Проверка перед завершением

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan test --filter=Api
```

И открыть `/docs/api`.

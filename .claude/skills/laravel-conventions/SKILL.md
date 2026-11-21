Соглашения Laravel для проекта комментариев

Краткая сводка лежит в CLAUDE.md. Здесь подробности и примеры.
Слои и куда класть код

| Что | Где | Пример | |---|---|---| | Разбор параметров запроса, ответ | app/Http/Controllers | CommentController | | Валидация входных данных | app/Http/Requests, app/Rules | StoreCommentRequest, ValidCommentMarkup | | Сценарии, кэш, очистка данных | app/Services | CommentService, CommentCache, CommentSanitizer | | Запросы к БД | app/Repositories | CommentRepository | | Форма JSON | app/Http/Resources | CommentResource | | Фиксированные наборы значений | app/Enums | CommentSort | | События | app/Events | CommentCreated |

Контроллер не содержит запросов к БД и приватных методов с логикой: он вызывает сервис. Интерфейс репозитория не нужен (KISS), подмена реализации не планируется.
Как добавить типовые вещи

Новое поле сортировки. Добавить case в CommentSort, убедиться, что на колонке есть индекс (миграция), добавить тест. Список значений в других местах не дублировать.

Новый запрос к БД. Метод в CommentRepository. Для вложенных данных использовать root_id, чтобы загрузка дерева оставалась одним запросом (без N+1).

Новая бизнес-операция. Метод в CommentService. Контроллер только вызывает его.

Новое поле формы. Правило в StoreCommentRequest и такая же проверка на клиенте. Сообщения об ошибках на сервере делаем понятными пользователю.
Кэш

    Ключ страницы: comments:v{версия}:p{per_page}:{sort}:{dir}:{page}.
    Размер страницы задаётся только в config/comments.php (per_page, env COMMENTS_PER_PAGE), в коде берётся через CommentRepository::perPage().
    Пустые страницы после первой не кэшируются (номер страницы задаёт клиент).
    Версия растёт при создании и удалении комментария (события модели Comment), поэтому вручную ключи не удаляем.
    Всё, что влияет на результат (новый параметр фильтра, размер страницы), должно входить в ключ или сопровождаться сбросом кэша.
    В кэш кладём массивы, а не Eloquent-модели и не объекты ресурсов.

Вложения

    Изображение JPG/GIF/PNG (исходник до 5 МБ и 5000 px по стороне) или TXT до 100 КБ. Поле attachment, запрос multipart.
    Тип определяет только AttachmentType::detect: по расширению и содержимому (getimagesize для картинок, finfo для текста), не по Content-Type клиента и не по getMimeType() (у тестовых файлов он берётся из имени).
    Лимиты в App\Rules\CommentAttachment, на фронтенде те же значения в CommentForm.vue.
    Фронтенд уже присылает уменьшенную картинку, но серверную обработку не убираем: запрос можно отправить в обход формы. JPEG поворачивается по EXIF Orientation (запасной путь, если не повернул браузер).
    AttachmentStorage: картинка всегда перекодируется через GD и вписывается в 320x240 без увеличения (прозрачность PNG/GIF сохраняется), TXT сохраняется как есть. Имя файла uuid, исходное имя только для показа (attachment_name).
    Файл сохраняется до записи в БД; если запись не удалась, файл удаляется (CommentService::create).
    Ошибки загрузки пишем в канал uploads (storage/logs/uploads-YYYY-MM-DD.log): отказ валидации в StoreCommentRequest::failedValidation, сбой обработки в AttachmentStorage. Персональные данные (e-mail, текст) туда не пишем.
    Файлы лежат на диске public (attachments/), nginx отдаёт /storage/ с X-Content-Type-Options: nosniff.

WebSocket (Reverb)

    CommentCreated отправляем через broadcast(new CommentCreated($comment))->toOthers() внутри try/catch: окно автора (X-Socket-ID) событие не получает.
    В событии только id, parent_id, root_id, user_name, parent_user_name. E-mail и текст не рассылаем: клиент сам запрашивает GET /comments.
    GET /comments/{comment}/page возвращает {page, root_id}: на какой странице ветка при текущей сортировке (CommentRepository::pageOfRoot, порядок как в paginateRoots: поле, затем id).
    Параметры списка (sort, dir, page) разбирает ListCommentsRequest: неверные значения заменяются значениями по умолчанию, без 422.
    Воркер держит код в памяти: после изменения событий или задач выполнить php artisan queue:restart.
    RabbitMQ: подключение и схема явно в config/queue.php (connections.rabbitmq). Обменник comments (direct), routing key = имя очереди, очереди в topology.queues: broadcasts (рассылки, CommentCreated::broadcastQueue) и default. Пакет сам объявляет только обменник, очереди и привязки объявляет php artisan rabbitmq:setup (идемпотентна, запускается при старте воркеров). Новую очередь добавлять в topology.queues и заводить для неё воркер.
    Воркеры: rabbitmq:consume (basic_consume), сервисы queue (broadcasts) и queue-default (default). --sleep=0.1 обязателен: пакет ждёт без блокировки и иначе спит 3 с, уведомления опаздывали бы до 3 с.
    Демо-данные: CommentSeeder (постоянные авторы из CommentSeeder::AUTHORS). В конце сбрасывает кэш, потому что DatabaseSeeder отключает события моделей.

Безопасность

    Поле сортировки принимаем только через Enum, направление только asc|desc.
    Текст комментария очищает CommentSanitizer. Разрешены <a href title>, <code>, <i>, <strong>. В БД лежит уже очищенный текст. Не ослаблять правила и не хранить исходный HTML.
    Ссылки только http(s). javascript: и подобные схемы отклоняются.
    Секреты только в .env, env() вызываем только в config/*.php.

Надёжность

    Сбой очереди, Reverb или Google reCAPTCHA не должен приводить к 500 при сохранении комментария: событие отправляем в try/catch с report($e).
    Миграции обратимы (down), внешние ключи и индексы заданы явно.

Стиль и комментарии

    PSR-12. Перед завершением работы: docker compose exec app ./vendor/bin/pint.
    Комментарии по-русски и о причине решения («почему»), а не о том, что делает строка.
    Сложные публичные методы документируем PHPDoc с форматом входа и выхода.
    Принципы DRY и KISS: повторяющийся код выносим, лишних абстракций не добавляем.

Проверка перед завершением

```bash

docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan test

Новые классы требуют docker compose exec app composer dump-autoload.

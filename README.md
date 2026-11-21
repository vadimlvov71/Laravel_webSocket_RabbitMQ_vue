<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Демо: живые обновления через WebSocket

```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=CommentSeeder   # 40 веток от 6 демо-авторов
```

1. Откройте http://localhost:8086 в двух окнах: обычном и инкогнито (или в двух браузерах).
2. В первом окне добавьте комментарий или ответьте на существующий.
3. Во втором окне появится уведомление («New comment from …» / «… replied to …»), список обновится, новый комментарий подсветится жёлтым. Кнопка Show прокручивает к нему, а если ветка на другой странице, открывает нужную.
4. Ответьте во втором окне на комментарий из первого: в первом придёт особое уведомление «… replied to your comment».
5. Если окно в фоне, в заголовке вкладки появляется счётчик: «(2) Comments».

Окно автора своё событие не получает (`broadcast(...)->toOthers()` по `X-Socket-ID`), оно обновляет список само после ответа сервера.

Путь события: `POST /comments` → `CommentCreated` → обменник RabbitMQ `comments` → очередь `broadcasts` → воркер `queue` → Reverb → Laravel Echo во всех остальных вкладках → `GET /comments`.

### RabbitMQ

Схема задана в `config/queue.php` (`connections.rabbitmq`) и объявляется командой `php artisan rabbitmq:setup` при старте воркеров:

```
обменник comments (direct) ──routing key = имя очереди──> broadcasts   рассылки в WebSocket, воркер queue
                                                      └──> default      остальные задачи, воркер queue-default
```

Воркеры запущены через `rabbitmq:consume` (подписка `basic_consume`), поэтому в http://localhost:15672 у каждой очереди виден потребитель. Чтобы увидеть сообщение в очереди: `docker compose stop queue`, добавьте комментарий, откройте Queues → broadcasts → Get messages, затем `docker compose start queue`.

После изменения кода событий или задач перезапустите воркер: `docker compose exec app php artisan queue:restart`.

## Поиск (Elasticsearch)

Поле «Search comments…» над таблицей ищет по тексту и имени автора: русская и английская морфология («очередь» найдёт «очереди»), опечатки («коментарий»), начало слова при наборе. Найденные слова подсвечены; клик открывает страницу с веткой и подсвечивает комментарий.

```bash
docker compose exec app php artisan comments:reindex   # создать индекс и загрузить все комментарии
```

- Новые и удалённые комментарии попадают в индекс сами: событие модели → задача `SyncCommentToSearch` → очередь `default` → воркер `queue-default` → Elasticsearch.
- Запросы к Elasticsearch идут через `Http` Laravel (REST API), без отдельного клиента: `App\Services\CommentSearch`.
- Если Elasticsearch не запущен, страница работает, поиск отвечает «Search is temporarily unavailable». Выключить совсем: `ELASTICSEARCH_ENABLED=false`.
- После изменения схемы индекса (`CommentSearch::MAPPING`) выполните `comments:reindex`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

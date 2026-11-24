# Как вносить изменения

Правила работы с Git в этом репозитории. Цель — чтобы по истории было понятно, что и зачем менялось, без чтения диффов.

## Коротко

1. Одна задача — одна ветка — один pull request.
2. В `main` напрямую не пушим: только через PR с зелёным CI.
3. Сообщения коммитов в стиле [Conventional Commits](https://www.conventionalcommits.org/ru/v1.0.0/).
4. После слияния PR ветка удаляется.

## Ветки

Ветка создаётся от свежего `main`:

```bash
git checkout main && git pull
git checkout -b feature/comment-attachments
```

Имя: `<тип>/<короткое-описание>` строчными латинскими буквами через дефис.

| Тип | Когда | Пример |
|---|---|---|
| `feature/` | новая функциональность | `feature/elasticsearch-search` |
| `fix/` | исправление ошибки | `fix/api-rate-limit-key` |
| `refactor/` | изменение кода без изменения поведения | `refactor/comment-repository-types` |
| `docs/` | только документация | `docs/scaling-wiki` |
| `test/` | только тесты | `test/attachment-validation` |
| `ci/` | GitHub Actions, сборка | `ci/larastan` |
| `chore/` | зависимости, конфиги, Docker | `chore/php-upload-limits` |

Название ветки должно совпадать с её содержимым. Если по ходу появилась другая задача, для неё нужна новая ветка.

## Коммиты

Формат:

```
<тип>(<область>): <что сделано>

<почему, если это не очевидно из заголовка>
```

- **Тип** — как у веток: `feat`, `fix`, `refactor`, `docs`, `test`, `ci`, `chore`, плюс `perf` (производительность) и `style` (форматирование без изменения логики).
- **Область** — часть проекта: `api`, `search`, `upload`, `ws` (WebSocket), `queue`, `cache`, `security`, `frontend`, `docker`, `readme`.
- **Заголовок** — по-английски, в повелительном наклонении («add», а не «added»), без точки в конце, до 72 символов.
- **Тело** — по желанию: причина изменения, ограничения, ссылка на задачу.
- **Ломающее изменение** отмечается `!` после области и строкой `BREAKING CHANGE:` в теле.

Примеры:

```
feat(search): add Elasticsearch full-text search via Http client
fix(api): use ApiClient token for rate limit key
fix(security)!: mask commenter email in API responses

BREAKING CHANGE: `email` now returns `a***@example.com` instead of the full address.
docs(readme): add table of contents and link to scaling wiki
ci: add PHPStan stage (Larastan level 6)
```

Так не надо:

| Плохо | Почему | Лучше |
|---|---|---|
| `fix` | непонятно, что исправлено | `fix(upload): reject TXT files larger than 100 KB` |
| `README file edit` | непонятно, что изменилось | `docs(readme): add CI stages table` |
| `security fix and wiki about 1M comments` | две задачи в одном коммите | два коммита в двух ветках |

Один коммит — одно логическое изменение, после которого проект собирается и тесты проходят. Мелкие исправления к уже сделанному коммиту склеиваются с ним до пуша:

```bash
git commit --fixup <hash>
git rebase -i --autosquash main
```

Переписывать историю можно только в своей ветке до слияния. `main` не переписывается никогда.

## Pull request

1. Перед пушем — проверки локально (те же, что в CI):
   ```bash
   docker compose exec -T app ./vendor/bin/pint --test
   docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G
   docker compose exec -T app php artisan test
   docker compose run --rm -e npm_config_cache=/tmp/.npm node npm run build
   ```
2. Если `main` ушёл вперёд, сначала подтянуть его в свою ветку, чтобы конфликты всплыли локально, а не на GitHub:
   ```bash
   git fetch origin && git merge origin/main
   ```
3. `git push -u origin <ветка>` и открыть PR в `main`.
4. Заголовок PR — в формате коммита. В описании 2–3 строки: что сделано и как проверить.
5. Слияние — после зелёного CI.

## После слияния

```bash
git checkout main && git pull
git branch -d <ветка>
```

Ветка на GitHub удаляется автоматически (Settings → General → «Automatically delete head branches»).

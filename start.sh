#!/usr/bin/env bash
# Поднимает проект с нуля одной командой. Повторный запуск безопасен: ничего
# не пересоздаёт и не удаляет, только доустанавливает и обновляет.
#
#   ./start.sh            сборка, зависимости, миграции, запуск всех сервисов
#   ./start.sh --seed     то же + демо-комментарии (CommentSeeder)
#   ./start.sh --fresh    пересоздать таблицы БД (данные удаляются) + демо-комментарии
#   ./start.sh --help

set -uo pipefail
cd "$(dirname "$0")"

# ---------- параметры ----------
SEED=false
FRESH=false

usage() { sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; }

for arg in "$@"; do
    case "$arg" in
        --seed)    SEED=true ;;
        --fresh)   FRESH=true; SEED=true ;;
        -h|--help) usage; exit 0 ;;
        *)         echo "Неизвестный параметр: $arg" >&2; usage; exit 1 ;;
    esac
done

# ---------- вывод ----------
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

success() { echo -e "${GREEN}✔${NC} $1"; }
info()    { echo -e "${BLUE}➜${NC} $1"; }
warn()    { echo -e "${YELLOW}! WARNING:${NC} $1"; }
error()   { echo -e "${RED}✘ ERROR:${NC} $1" >&2; }

# Выполняет команду, вывод показывает только при ошибке (последние 30 строк).
# run "Описание" команда аргументы...
run() {
    local title="$1"; shift
    local log code
    log=$(mktemp)

    info "$title"
    if "$@" >"$log" 2>&1; then
        success "$title"
        rm -f "$log"
        return 0
    fi

    code=$?
    error "$title (код выхода: $code)"
    echo -e "${RED}--- последние строки вывода ---${NC}" >&2
    tail -n 30 "$log" >&2
    echo -e "${RED}-------------------------------${NC}" >&2
    rm -f "$log"
    exit "$code"
}

# То же, но ошибка не останавливает скрипт: для необязательных шагов.
try() {
    local title="$1"; shift
    info "$title"
    if "$@" >/dev/null 2>&1; then
        success "$title"
    else
        warn "$title — не получилось, проект работает и без этого"
    fi
}

# Значение из .env (последнее, если ключ повторяется), без кавычек.
env_get() {
    local value
    value=$(grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | tr -d '"'"'")
    echo "${value:-$2}"
}

# Записывает KEY=value в .env: заменяет существующую строку или добавляет новую.
env_set() {
    if grep -qE "^$1=" .env; then
        sed -i "s|^$1=.*|$1=$2|" .env
    else
        echo "$1=$2" >> .env
    fi
}

# ---------- проверки окружения ----------
command -v docker >/dev/null 2>&1   || { error "Docker не установлен"; exit 1; }
docker compose version >/dev/null 2>&1 || { error "Не найден плагин 'docker compose' (нужен Docker Compose v2)"; exit 1; }
docker info >/dev/null 2>&1         || { error "Docker daemon не запущен или нет прав (попробуйте: sudo usermod -aG docker \$USER и перелогиньтесь)"; exit 1; }

[ -f docker-compose.yml ] || { error "docker-compose.yml не найден в $(pwd)"; exit 1; }
[ -f composer.json ]      || { error "composer.json не найден в $(pwd)"; exit 1; }

# ---------- .env ----------
if [ ! -f .env ]; then
    [ -f .env.example ] || { error "Нет ни .env, ни .env.example"; exit 1; }
    cp .env.example .env
    success ".env создан из .env.example"
fi

# контейнеры пишут файлы от имени текущего пользователя, иначе vendor/ и storage/ окажутся у root
env_set UID "$(id -u)"
env_set GID "$(id -g)"

# ---------- образы и зависимости (до запуска сервисов) ----------
# Воркеры, Reverb и Pulse стартуют сразу после `up` и без vendor/ упали бы,
# поэтому зависимости ставятся отдельными одноразовыми контейнерами заранее.
run "Сборка образов (в первый раз несколько минут)" docker compose build

run "Composer: установка PHP-зависимостей" \
    docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist --no-progress

if [ -z "$(env_get APP_KEY '')" ]; then
    run "Генерация APP_KEY" docker compose run --rm --no-deps app php artisan key:generate --force
else
    success "APP_KEY уже задан"
fi

# Без public/build страница отвечает 500 (нет Vite manifest), если dev-сервер не запущен.
run "Сборка фронтенда (npm ci + vite build)" \
    docker compose run --rm --no-deps node sh -c "npm ci --no-audit --no-fund && npm run build"

# public/hot оставляет dev-сервер Vite; если он остался от прошлого запуска,
# страница грузила бы скрипты с остановленного сервера. Dev-сервер создаст его заново.
rm -f public/hot

# ---------- запуск ----------
# --wait: ждём, пока все сервисы запущены, а сервисы с healthcheck — healthy
run "Запуск контейнеров и ожидание готовности (MySQL, Redis, RabbitMQ, Elasticsearch)" \
    docker compose up -d --wait --wait-timeout 300

# ---------- база и хранилище ----------
if $FRESH; then
    run "Пересоздание таблиц БД (migrate:fresh)" docker compose exec -T app php artisan migrate:fresh --force
else
    run "Миграции БД" docker compose exec -T app php artisan migrate --force
fi

if [ -L public/storage ]; then
    success "Ссылка public/storage уже есть"
else
    run "Ссылка public/storage для вложений" docker compose exec -T app php artisan storage:link
fi

# ---------- данные и поиск ----------
if $SEED; then
    # сидер сам загружает индекс Elasticsearch
    run "Демо-комментарии (CommentSeeder)" docker compose exec -T app php artisan db:seed --class=CommentSeeder --force
else
    try "Индекс поиска Elasticsearch" docker compose exec -T app php artisan comments:reindex
fi

# ---------- воркеры ----------
# при повторном запуске подхватывают новый код и конфиг
run "Перезапуск воркеров (queue, queue-default, reverb, pulse)" \
    docker compose restart queue queue-default reverb pulse

sleep 3
FAILED=()
for service in app nginx node queue queue-default reverb pulse mysql redis rabbitmq elasticsearch; do
    docker compose ps --status running --services | grep -qx "$service" || FAILED+=("$service")
done

if [ ${#FAILED[@]} -gt 0 ]; then
    for service in "${FAILED[@]}"; do
        error "Сервис $service не запущен"
        docker compose logs --tail=20 "$service" >&2
    done
    exit 1
fi
success "Все сервисы работают"

# ---------- итог ----------
APP_PORT=$(env_get APP_PORT 8080)
PMA_PORT=$(env_get PMA_PORT 8081)
RABBITMQ_UI_PORT=$(env_get RABBITMQ_UI_PORT 15672)
RABBITMQ_USER=$(env_get RABBITMQ_USER guest)
RABBITMQ_PASSWORD=$(env_get RABBITMQ_PASSWORD guest)

echo
echo -e "${BOLD}Проект запущен${NC}"
echo -e "  Сайт:             ${GREEN}http://localhost:${APP_PORT}${NC}"
echo -e "  Документация API: http://localhost:${APP_PORT}/docs/api"
echo -e "  Pulse:            http://localhost:${APP_PORT}/pulse"
echo -e "  RabbitMQ:         http://localhost:${RABBITMQ_UI_PORT}  (${RABBITMQ_USER} / ${RABBITMQ_PASSWORD})"
echo -e "  phpMyAdmin:       http://localhost:${PMA_PORT}"
echo
echo "  Ключ для REST API:  docker compose exec app php artisan api:client-create \"Partner\""
echo "  Тесты:              docker compose exec app php artisan test"
echo "  Остановить:         docker compose down"
$SEED || echo "  Демо-комментарии:   ./start.sh --seed"

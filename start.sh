
#!/usr/bin/env bash
# Запуск контейнеризированного Laravel-проекта
# Использование: ./start.sh
 
set -uo pipefail
cd "$(dirname "$0")"
 
# ---------- цвета ----------
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
 
success() { echo -e "${GREEN}✔ SUCCESS:${NC} $1"; }
info()    { echo -e "${BLUE}➜${NC} $1"; }
warn()    { echo -e "${YELLOW}! WARNING:${NC} $1"; }
error()   { echo -e "${RED}✘ ERROR:${NC} $1" >&2; }
 
# ---------- запуск команды с проверкой результата ----------
# run "Описание" команда аргументы...
run() {
    local title="$1"; shift
    local log
    log=$(mktemp)
 
    info "$title"
    if "$@" >"$log" 2>&1; then
        success "$title"
        rm -f "$log"
    else
        local code=$?
        error "$title (код выхода: $code)"
        echo -e "${RED}--- вывод ошибки ---${NC}" >&2
        tail -n 30 "$log" >&2
        echo -e "${RED}--------------------${NC}" >&2
        rm -f "$log"
        exit "$code"
    fi
}
 
# ---------- проверки ----------
command -v docker >/dev/null 2>&1 || { error "Docker не установлен"; exit 1; }
docker compose version >/dev/null 2>&1 || { error "Не найден плагин 'docker compose'"; exit 1; }
docker info >/dev/null 2>&1 || { error "Docker daemon не запущен или нет прав (попробуйте: sudo usermod -aG docker \$USER)"; exit 1; }
 
[ -f docker-compose.yml ] || { error "docker-compose.yml не найден в $(pwd)"; exit 1; }
[ -f composer.json ] || { error "composer.json не найден в $(pwd). Это не Laravel-проект? (автору проекта: сначала ./init-project.sh)"; exit 1; }
 
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        warn ".env не найден, создан из .env.example. Проверьте DB_*, REDIS_*, RABBITMQ_* (хосты = имена сервисов)"
    else
        error "Файл .env не найден и нет .env.example"
        exit 1
    fi
fi
 
# UID/GID текущего пользователя (права на файлы)
grep -q '^UID=' .env || echo "UID=$(id -u)" >> .env
grep -q '^GID=' .env || echo "GID=$(id -g)" >> .env
 
# ---------- запуск ----------
run "Сборка и запуск контейнеров" docker compose up -d --build
 
# ждём, пока app-контейнер реально запустится
info "Ожидание контейнера app..."
for i in {1..30}; do
    if docker compose exec -T app php -v >/dev/null 2>&1; then break; fi
    sleep 2
    if [ "$i" -eq 30 ]; then error "Контейнер app не отвечает"; docker compose logs --tail=30 app; exit 1; fi
done
success "Контейнер app готов"
 
run "Установка composer-зависимостей" docker compose exec -T app composer install --no-interaction --prefer-dist
 
# key:generate только если ключа ещё нет
if grep -qE '^APP_KEY=.+' .env; then
    success "APP_KEY уже задан, пропускаем key:generate"
else
    run "Генерация APP_KEY" docker compose exec -T app php artisan key:generate --force
fi
 
run "Миграции БД" docker compose exec -T app php artisan migrate --force
run "Создание storage:link" docker compose exec -T app php artisan storage:link --force
 
# queue-контейнер мог стартовать до composer install и упасть, перезапускаем
run "Перезапуск queue worker" docker compose restart queue
sleep 3
if [ "$(docker compose ps --status running --services | grep -c '^queue$')" -eq 1 ]; then
    success "Queue worker работает"
else
    error "Queue worker не запущен"
    docker compose logs --tail=30 queue >&2
    exit 1
fi

if [ "$(docker compose ps --status running --services | grep -c '^reverb$')" -eq 1 ]; then
    success "Reverb работает"
else
    error "Reverb не запущен"
    docker compose logs --tail=30 reverb >&2
    exit 1
fi
# ---------- итог ----------
APP_PORT_VAL=$(grep -E '^APP_PORT=' .env | cut -d= -f2); APP_PORT_VAL=${APP_PORT_VAL:-8080}
echo
success "Проект запущен: http://localhost:${APP_PORT_VAL}"
docker compose ps

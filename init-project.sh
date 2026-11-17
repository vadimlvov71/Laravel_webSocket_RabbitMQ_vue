#!/usr/bin/env bash
# ОДНОРАЗОВЫЙ скрипт для автора проекта: создаёт Laravel 12 с нуля внутри docker.
# После него коммитим проект в git, а остальные разворачивают его через ./start.sh
set -uo pipefail
cd "$(dirname "$0")"
 
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
success() { echo -e "${GREEN}✔ SUCCESS:${NC} $1"; }
info()    { echo -e "${BLUE}➜${NC} $1"; }
warn()    { echo -e "${YELLOW}! WARNING:${NC} $1"; }
error()   { echo -e "${RED}✘ ERROR:${NC} $1" >&2; }
 
run() {
    local title="$1"; shift
    local log; log=$(mktemp)
    info "$title"
    if "$@" >"$log" 2>&1; then
        success "$title"; rm -f "$log"
    else
        local code=$?
        error "$title (код выхода: $code)"
        echo -e "${RED}--- вывод ошибки ---${NC}" >&2
        tail -n 30 "$log" >&2
        echo -e "${RED}--------------------${NC}" >&2
        rm -f "$log"; exit "$code"
    fi
}
 
# ---------- проверки ----------
command -v docker >/dev/null 2>&1 || { error "Docker не установлен"; exit 1; }
docker info >/dev/null 2>&1 || { error "Docker daemon не запущен или нет прав"; exit 1; }
[ -f docker-compose.yml ] || { error "docker-compose.yml не найден"; exit 1; }
if [ -f composer.json ]; then
    error "composer.json уже существует — проект создан. Для запуска используйте ./start.sh"
    exit 1
fi
 
# ---------- временный .env для compose ----------
if [ ! -f .env ]; then
    [ -f .env.docker.example ] && cp .env.docker.example .env || : > .env
fi
grep -q '^UID=' .env || echo "UID=$(id -u)" >> .env
grep -q '^GID=' .env || echo "GID=$(id -g)" >> .env
 
run "Сборка PHP-образа" docker compose build app
 
# ---------- создание Laravel 12 ----------
run "Создание Laravel 12 (composer create-project)" \
    docker compose run --rm --no-deps app sh -c '
        composer create-project laravel/laravel:^12.0 /tmp/laravel --no-interaction --no-scripts --prefer-dist &&
        tar -C /tmp/laravel --exclude=./.env --exclude=./.env.example -cf - . | tar -C /var/www/html -xf -'
 
# ---------- .env.example с docker-настройками ----------
cat > .env.example <<'ENV'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_PORT=8080
VITE_PORT=5173
PMA_PORT=8081
 
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
 
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
 
# --- MySQL ---
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret
DB_ROOT_PASSWORD=root
 
# --- Redis ---
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
 
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
 
# --- Queue / RabbitMQ ---
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
 
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
 
MAIL_MAILER=log
 
# --- Google reCAPTCHA ---
RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET_KEY=
 
VITE_APP_NAME="${APP_NAME}"
ENV
rm -f .env.docker.example
cp .env.example .env
echo "UID=$(id -u)" >> .env
echo "GID=$(id -g)" >> .env
success "Создан .env.example и .env"
 
# ---------- пакеты ----------
PHP_RUN=(docker compose run --rm --no-deps app)
run "Пакет RabbitMQ (laravel-queue-rabbitmq)" "${PHP_RUN[@]}" composer require vladimir-yuldashev/laravel-queue-rabbitmq --no-interaction
run "Пакет Google reCAPTCHA"                  "${PHP_RUN[@]}" composer require google/recaptcha --no-interaction
run "Пакет OpenAPI (Scramble)"                "${PHP_RUN[@]}" composer require dedoc/scramble --no-interaction
run "Пакет Debugbar (dev)"                    "${PHP_RUN[@]}" composer require barryvdh/laravel-debugbar --dev --no-interaction
 
# ---------- подключение rabbitmq в config/queue.php ----------
if ! grep -q "'rabbitmq'" config/queue.php; then
    awk '
        { print }
        /^\s*.connections. => \[/ && !done {
            print ""
            print "        \x27rabbitmq\x27 => ["
            print "            \x27driver\x27 => \x27rabbitmq\x27,"
            print "            \x27hosts\x27 => [["
            print "                \x27host\x27 => env(\x27RABBITMQ_HOST\x27, \x27127.0.0.1\x27),"
            print "                \x27port\x27 => env(\x27RABBITMQ_PORT\x27, 5672),"
            print "                \x27user\x27 => env(\x27RABBITMQ_USER\x27, \x27guest\x27),"
            print "                \x27password\x27 => env(\x27RABBITMQ_PASSWORD\x27, \x27guest\x27),"
            print "                \x27vhost\x27 => env(\x27RABBITMQ_VHOST\x27, \x27/\x27),"
            print "            ]],"
            print "            \x27queue\x27 => env(\x27RABBITMQ_QUEUE\x27, \x27default\x27),"
            print "        ],"
            done=1
        }' config/queue.php > config/queue.php.new && mv config/queue.php.new config/queue.php
    success "Подключение rabbitmq добавлено в config/queue.php"
fi
 
# ---------- фронтенд ----------
NODE_RUN=(docker compose run --rm --no-deps -e npm_config_cache=/tmp/.npm node)
run "npm install"                                   "${NODE_RUN[@]}" npm install
run "Vue 3, Laravel Echo (npm)"                     "${NODE_RUN[@]}" npm install vue @vitejs/plugin-vue laravel-echo pusher-js
 
echo
success "Проект создан. Запускаем start.sh..."
exec ./start.sh

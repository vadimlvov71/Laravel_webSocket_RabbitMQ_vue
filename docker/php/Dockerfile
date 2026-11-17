FROM php:8.4-fpm
 
ARG UID=1000
ARG GID=1000
 
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mysqli bcmath intl zip gd pcntl sockets opcache exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
 
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
 
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
 
# пользователь с тем же UID/GID, что и на хосте (чтобы не было проблем с правами)
RUN groupadd -g ${GID} app 2>/dev/null || true \
    && useradd -u ${UID} -g ${GID} -m -s /bin/bash app 2>/dev/null || true
 
USER ${UID}:${GID}
WORKDIR /var/www/html

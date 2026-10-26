# PHP-FPM 应用镜像。运行时不挂载宿主机源码，代码和依赖都来自镜像。
FROM php:8.3-fpm

WORKDIR /var/www/html

RUN sed -i \
        -e 's|http://deb.debian.org/debian-security|https://mirrors.aliyun.com/debian-security|g' \
        -e 's|http://deb.debian.org/debian|https://mirrors.aliyun.com/debian|g' \
        /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        zip \
        unzip \
        libzip-dev \
        libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.2.0.tar.gz \
        | tar -xz -C /tmp \
    && cd /tmp/phpredis-6.2.0 \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/phpredis-6.2.0 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 先安装依赖，源码变更时可以复用 Composer 这一层缓存。
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

# 依赖安装跳过脚本后，在完整源码存在时生成 Laravel 包清单。
RUN php artisan package:discover --ansi \
    && mkdir -p \
        storage/framework/cache \
        storage/framework/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/app/entrypoint.sh /usr/local/bin/pc-management-entrypoint
RUN chmod +x /usr/local/bin/pc-management-entrypoint

USER www-data

EXPOSE 9000

ENTRYPOINT ["pc-management-entrypoint"]
CMD ["php-fpm", "-F"]

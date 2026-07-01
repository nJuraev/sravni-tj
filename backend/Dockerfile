# Laravel backend — PHP 8.4 CLI + расширения для PostgreSQL.
# 8.4, т.к. актуальная Laravel 13 + symfony 8.1 требуют php >= 8.4.1.
FROM php:8.4-cli

# Системные зависимости + расширения PHP (pdo_pgsql для Postgres, zip/intl для Laravel).
# Опции Acquire отключают кэширование apt — за кэширующим/прозрачным прокси
# большой индекс Packages приходит обрезанным («File has unexpected size»),
# из-за чего apt-get update падает. No-Cache/BrokenProxy обходят это.
RUN printf 'Acquire::http::No-Cache "true";\nAcquire::BrokenProxy "true";\nAcquire::http::Pipeline-Depth "0";\n' > /etc/apt/apt.conf.d/99fixproxy \
    && apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer из официального образа.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- Сборка приложения (для деплоя, напр. Railway) ---
# Локально docker-compose монтирует ./backend в /app поверх этих слоёв и
# переопределяет команду, поэтому COPY/зависимости ниже не мешают разработке;
# для самодостаточного образа (Railway) они кладут код и vendor внутрь.

# Слой зависимостей кэшируется отдельно от исходников.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Исходный код приложения.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chmod -R ug+rw storage bootstrap/cache

EXPOSE 8000

# Дефолтная команда (Railway переопределяет через railway.json startCommand,
# docker-compose — через свой command). Порт берётся из $PORT при наличии.
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]

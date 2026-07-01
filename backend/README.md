<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

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

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

# Sravni.tj — Backend (REST API)

REST API витрины (чтение продуктов/банков) + приём заявок (`leads`) с доставкой на
email банка. Контракт API — `../docs/api/contracts.md` (ЗАМОРОЖЕН). Бизнес-правила —
`../docs/specs/backend.md`. Схема БД — `../docs/db/schema.md`.

> **PHP 8.3+ обязателен** (Laravel 13). Локальный PHP 7.4 несовместим — собирать,
> мигрировать и тестировать только в Docker (`php:8.3`).

## Эндпоинты

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/products` | список активных продуктов (фильтры, сортировка, пагинация) |
| GET | `/api/products/{id}` | карточка продукта (404 для скрытого/неактивного) |
| GET | `/api/banks` | активные банки |
| POST | `/api/leads` | приём заявки → запись + email на `banks.email` |

Видимость: продукт отдаётся ТОЛЬКО при `products.status='active'` И `banks.status='active'`.

## Переменные окружения

`.env` НЕ коммитится. Создайте его в Docker по списку ниже (минимум для работы API).

### База данных (PostgreSQL — обязательно)

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db            # имя сервиса postgres в docker-compose
DB_PORT=5432
DB_DATABASE=sravni
DB_USERNAME=sravni
DB_PASSWORD=secret
DB_SSLMODE=prefer
```

> `config/database.php`: дефолтное соединение — `pgsql`. Парсер (Go) пишет в ту же БД;
> backend читает `products`/`banks` и пишет только в `leads`.

### Почта (доставка заявок банкам)

```dotenv
MAIL_MAILER=smtp          # в проде; log/array — для отладки/тестов
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="no-reply@sravni.tj"
MAIL_FROM_NAME="${APP_NAME}"
```

### Очереди (письма ставятся в очередь — best-effort доставка)

```dotenv
QUEUE_CONNECTION=database   # или redis; для синхронной отправки — sync
```

> `POST /api/leads` ставит письмо в очередь ПОСЛЕ успешной записи лида. Сбой почты не
> теряет лид и не меняет HTTP-код (всегда `201`). Для реальной доставки запустите воркер:
> `php artisan queue:work`. При `QUEUE_CONNECTION=database` сначала примените миграции
> очередей (`jobs` уже есть в `database/migrations`).

### Базовое приложение

```dotenv
APP_NAME="Sravni.tj"
APP_ENV=local
APP_KEY=               # php artisan key:generate
APP_DEBUG=false        # в проде false: ошибки 5xx отдаются как {"message":"Server error."}
APP_URL=http://localhost
APP_LOCALE=ru          # дефолтная локаль; Accept-Language: ru|tg переключает язык ошибок
```

## Запуск в Docker (php:8.3)

```bash
composer install
php artisan key:generate
php artisan migrate --force          # PostgreSQL обязателен (jsonb/GIN/CHECK)
php artisan queue:work               # воркер доставки писем (опционально)
```

## Тесты

Feature-тесты используют `RefreshDatabase` и Postgres-специфику миграций, поэтому
**требуют PostgreSQL** (sqlite не подойдёт: jsonb, GIN, `CHECK ... ~*`). `phpunit.xml`
выставляет `DB_CONNECTION=pgsql` — задайте `DB_*` тестового контейнера в окружении.

```bash
php artisan test
```

Покрытие: видимость (active+active), фильтры (category/currency/amount/term/rate/features),
точный режим по тарифной сетке, сортировка, 422 на невалидные фильтры, приём заявки,
серверное определение `bank_id`, `is_partner` не влияет на адрес, `consent!=true → 422`,
404 на скрытый продукт, 422 на лид по скрытому продукту.

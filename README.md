# Ada Scanner (PHAPI + Swoole)

Single-process PHAPI app using Swoole with MySQL as the source of truth and optional Redis for cache acceleration.

## Requirements

- PHP 8.3+
- Swoole extension (native or portable)
- MySQL 8.0+ (or compatible)
- Optional: Redis

## Setup

1) Install dependencies:

```bash
composer install
```

2) Create `config.php` (required):

Copy the example and edit:

```bash
cp config.php.example config.php
```

Key values:

```
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=your_password
MYSQL_DATABASE=ada_scanner
```

3) Optional Redis cache:

```
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_AUTH=
REDIS_DB=
REDIS_TIMEOUT=1.0
```

## Run

From `new-version/`:

```bash
php vendor/bin/phapi-run app.php
```

Then open:

```
http://127.0.0.1:9504
```

## Auth

Create `auth.json` in the project root (or in `new-version/`) with:

```json
{
  "username": "admin",
  "password": "amada"
}
```

Env vars override the JSON values (from `.env` or shell):

```
APP_USER=admin
APP_PASS=amada
```

## WAVE API

Set your project API key inside the UI (Project Settings) after logging in.

## One-shot DB reset (admin only)

Enable the endpoint explicitly:

```
APP_ALLOW_RESET=1
```

Then call:

```
POST /api/admin/reset-db
```

This drops all tables, recreates the schema, and seeds a fresh default project.

## Notes

- MySQL is the only persistent store. Redis is optional and used only for cache/summary acceleration.
- This app is built for Swoole runtimes only.

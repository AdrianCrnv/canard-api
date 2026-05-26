# CLAUDE.md — Laravel Docker Airport Inspection API

## Project Overview

Airport/runway drone inspection management system. Laravel 13 REST API, fully containerized with Docker. Manages airports, runways, drones, flights, inspections (lights, markings, FOD, ILS, PAPI, etc.), and multi-user RBAC.

---

## Architecture

```
laravel-docker2/
├── docker-compose.yml        # Multi-service orchestration
├── .env                      # Docker-level environment variables
├── php/
│   ├── Dockerfile            # PHP 8.4-FPM Alpine with extensions
│   └── local.ini             # PHP runtime settings
├── nginx/conf.d/default.conf # Nginx reverse proxy
├── mysql/my.cnf              # MySQL 8.4 settings
└── src/                      # Laravel 13 application root
    ├── app/Http/Controllers/ # 60+ API controllers
    ├── app/Models/           # 175+ Eloquent models
    ├── routes/api.php        # All protected API routes
    ├── composer.json
    └── package.json
```

---

## Docker Services

| Service      | Image                 | Port (host→container) | Role                   |
|--------------|-----------------------|-----------------------|------------------------|
| nginx        | nginx:1.27-alpine     | 8010→80               | Reverse proxy / webroot |
| php          | php:8.4-fpm-alpine    | 9000 (internal)       | Laravel runtime        |
| mysql        | mysql:8.4             | 3307→3306             | Primary database       |
| phpmyadmin   | phpmyadmin:5.2        | 8081→80               | DB admin GUI           |

Network: `laravel_net` (bridge). Volume: `mysql_data` (persistent).

---

## Local URLs

- Laravel API: http://localhost:8010
- phpMyAdmin:  http://localhost:8081
- Swagger docs: http://localhost:8010/api/documentation

---

## Tech Stack

- **PHP 8.4** / **Laravel 13.8**
- **MySQL 8.4** — utf8mb4, InnoDB
- **Laravel Sanctum 4.3** — API token authentication
- **Spatie Laravel Permission 7.4** — RBAC (roles + permissions)
- **Spatie Media Library 11.22** — file/media management
- **L5 Swagger 11** — OpenAPI/Swagger auto-generated docs
- **Vite 8** + **Tailwind CSS 4** — frontend build (minimal SPA shell)

---

## Common Commands

All commands run **inside the PHP container** unless noted.

```bash
# Start all services
docker compose up -d

# Enter the PHP container
docker exec -it laravel2_php sh

# Inside the container:
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed        # optional seeders
php artisan l5-swagger:generate

# Run tests
php artisan test
# or
composer test

# Dev mode (server + queue + logs + Vite)
composer dev

# Build frontend assets
npm run build
```

---

## Database Credentials

From `.env` (Docker level):

```
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret
DB_ROOT_PASSWORD=rootsecret
```

MySQL is exposed on host port **3307** (to avoid conflicts with local MySQL).

---

## API Authentication

All routes (except `POST /api/login`) require a Sanctum Bearer token:

```
Authorization: Bearer <token>
```

Login endpoint returns the token.

---

## Key Domain Entities

- **Airports / Runways / Taxiways / Aprons / Stands**
- **Aircraft / Drones / Aircraft Parts**
- **Operations / Tasks / Flights**

### Inspection Types

| Code | Meaning |
|------|---------|
| ALS | Approach Light System |
| ILS | Instrument Landing System |
| PAPI | Precision Approach Path Indicator |
| FOD | Foreign Object Debris |
| PCI | Pavement Condition Index |
| VOR | VHF Omnidirectional Range |
| WDI | Wind Direction Indicator |
| ETOD | Estimated Time of Departure |

---

## PHP Configuration Notes (`php/local.ini`)

- Memory limit: 256 MB
- Max upload / POST size: 64 MB
- Execution timeout: 120 s
- Timezone: `Europe/Madrid`
- OPcache enabled (revalidates on each request in development)

---

## Nginx Notes (`nginx/conf.d/default.conf`)

- Root: `/var/www/html/public`
- FastCGI backend: `php:9000`
- `try_files` for SPA/API routing
- Static assets cached for 1 year (`public, immutable`)
- Blocks access to hidden files (`.env` protection)
- Security headers: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection

---

## MySQL Notes (`mysql/my.cnf`)

- Buffer pool: 256 MB
- Max connections: 100
- Timezone: `+01:00` (Madrid)
- Strict SQL mode compatible with Laravel

---

## Queue & Cache

Both use the **database** driver (no Redis required in development):

```
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Image/video processing runs via queued jobs: `LightsProcessingJob`, `MarkingsProcessingJob`.

---

## Adding a New API Endpoint

1. Create model + migration in `src/database/migrations/`
2. Add Eloquent model in `src/app/Models/`
3. Create controller in `src/app/Http/Controllers/` with Swagger `#[OA\*]` attributes
4. Register route in `src/routes/api.php` inside the Sanctum middleware group
5. Regenerate Swagger: `php artisan l5-swagger:generate`

---

## Environment Files

| File | Scope |
|------|-------|
| `.env` (root) | Docker Compose variables (ports, DB credentials for containers) |
| `src/.env` | Laravel app config (APP_KEY, DB_HOST=mysql, etc.) |
| `src/.env.example` | Template — commit this, never commit `src/.env` |

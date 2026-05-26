# Airport Inspection Management System

Sistema de gestión de inspecciones aeroportuarias mediante drones. API REST construida con Laravel 13, completamente contenedorizada con Docker.

---

## Tabla de contenidos

- [Requisitos](#requisitos)
- [Inicio rápido](#inicio-rápido)
- [Servicios](#servicios)
- [URLs locales](#urls-locales)
- [Configuración del entorno](#configuración-del-entorno)
- [Comandos útiles](#comandos-útiles)
- [Stack tecnológico](#stack-tecnológico)
- [Dominio de la aplicación](#dominio-de-la-aplicación)
- [Autenticación](#autenticación)
- [Documentación de la API](#documentación-de-la-api)

---

## Requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) 24+
- Docker Compose v2

---

## Inicio rápido

```bash
# 1. Clonar el repositorio
git clone <repo-url> laravel-docker2
cd laravel-docker2

# 2. Copiar variables de entorno de Laravel
cp src/.env.example src/.env

# 3. Levantar los contenedores
docker compose up -d --build

# 4. Instalar dependencias PHP
docker exec -it laravel2_php composer install

# 5. Generar clave de aplicación
docker exec -it laravel2_php php artisan key:generate

# 6. Ejecutar migraciones
docker exec -it laravel2_php php artisan migrate

# 7. (Opcional) Poblar la base de datos
docker exec -it laravel2_php php artisan db:seed

# 8. Generar documentación Swagger
docker exec -it laravel2_php php artisan l5-swagger:generate
```

La aplicación estará disponible en **http://localhost:8010**.

---

## Servicios

| Servicio    | Imagen               | Puerto (host→cont.) | Descripción              |
|-------------|----------------------|---------------------|--------------------------|
| nginx       | nginx:1.27-alpine    | **8010**→80         | Proxy inverso / webroot  |
| php         | php:8.4-fpm-alpine   | 9000 (interno)      | Runtime de Laravel       |
| mysql       | mysql:8.4            | **3307**→3306       | Base de datos principal  |
| phpmyadmin  | phpmyadmin:5.2       | **8081**→80         | Administración de BD     |

---

## URLs locales

| Recurso            | URL                                       |
|--------------------|-------------------------------------------|
| API                | http://localhost:8010                     |
| phpMyAdmin         | http://localhost:8081                     |
| Swagger / OpenAPI  | http://localhost:8010/api/documentation   |

---

## Configuración del entorno

### `.env` (raíz del proyecto — Docker Compose)

```env
DB_ROOT_PASSWORD=rootsecret
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret
```

### `src/.env` (Laravel)

Copia `src/.env.example` y ajusta si es necesario. Los valores clave para Docker:

```env
DB_CONNECTION=mysql
DB_HOST=mysql        # nombre del servicio Docker, no localhost
DB_PORT=3306
DB_DATABASE=laravel

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

---

## Comandos útiles

```bash
# Entrar al contenedor PHP
docker exec -it laravel2_php sh

# Ver logs de todos los servicios
docker compose logs -f

# Parar todos los servicios
docker compose down

# Parar y eliminar volúmenes (borra la base de datos)
docker compose down -v

# Ejecutar tests
docker exec -it laravel2_php php artisan test

# Formatear código
docker exec -it laravel2_php ./vendor/bin/pint

# Cola de trabajos
docker exec -it laravel2_php php artisan queue:work

# Limpiar caché de configuración
docker exec -it laravel2_php php artisan config:clear

# Reconstruir imagen PHP tras cambiar el Dockerfile
docker compose build php
```

---

## Stack tecnológico

### Backend

| Tecnología | Versión | Uso |
|-----------|---------|-----|
| PHP | 8.4 | Runtime |
| Laravel | 13.8 | Framework |
| MySQL | 8.4 | Base de datos |
| Laravel Sanctum | 4.3 | Autenticación API (tokens) |
| Spatie Permission | 7.4 | Control de acceso por roles (RBAC) |
| Spatie Media Library | 11.22 | Gestión de archivos y medios |
| L5 Swagger | 11 | Documentación OpenAPI/Swagger |

### Frontend

| Tecnología | Versión | Uso |
|-----------|---------|-----|
| Vite | 8 | Bundler |
| Tailwind CSS | 4 | Estilos |

### Extensiones PHP incluidas

`pdo_mysql` · `mbstring` · `zip` · `exif` · `bcmath` · `gd` · `opcache` · `intl` · `pcntl` · `redis`

### Infraestructura

| Componente | Configuración |
|-----------|--------------|
| Nginx | Proxy inverso, caché de estáticos 1 año, cabeceras de seguridad |
| PHP-FPM | 256 MB RAM, 64 MB upload, OPcache, timezone Europe/Madrid |
| MySQL | Buffer pool 256 MB, utf8mb4, modo estricto compatible con Laravel |

---

## Dominio de la aplicación

### Entidades principales

- **Aeropuertos** — Pistas, Calles de rodaje, Plataformas, Stands
- **Aeronaves** — Drones, Partes de aeronave
- **Operaciones** — Tareas, Vuelos

### Tipos de inspección soportados

| Código | Descripción |
|--------|-------------|
| ALS | Approach Light System |
| ILS | Instrument Landing System |
| PAPI | Precision Approach Path Indicator |
| FOD | Foreign Object Debris |
| PCI | Pavement Condition Index |
| VOR | VHF Omnidirectional Range |
| WDI | Wind Direction Indicator |
| ETOD | Estimated Time of Departure |
| Luces | Luces de pista, rodaje e inundación |
| Marcas | Defectos en marcas de pista |
| Balizas | Balizas de aeródromo |
| Vigilancia | Sistemas de vigilancia |

### Gestión de usuarios

- Empresas, Operadores, Clientes, Gestores de aeropuerto
- Roles y permisos granulares vía Spatie Permission
- Subida de archivos en chunks y gestión de medios
- Procesado asíncrono de imágenes/vídeo (jobs en cola)

---

## Autenticación

La API usa **Laravel Sanctum** con tokens Bearer.

```bash
# Login
POST /api/login
Content-Type: application/json

{ "email": "user@example.com", "password": "password" }

# Respuesta
{ "token": "1|abc123..." }

# Usar el token en las siguientes peticiones
Authorization: Bearer 1|abc123...
```

Todos los endpoints excepto `POST /api/login` requieren autenticación.

---

## Documentación de la API

La documentación Swagger/OpenAPI se genera automáticamente a partir de los atributos PHP en los controladores.

```bash
# Regenerar documentación
docker exec -it laravel2_php php artisan l5-swagger:generate
```

Disponible en: http://localhost:8010/api/documentation

---

## Estructura de carpetas relevante

```
src/
├── app/
│   ├── Http/Controllers/     # 60+ controladores API
│   ├── Models/               # 175+ modelos Eloquent
│   └── Jobs/                 # Jobs asíncronos (procesado de imágenes/vídeo)
├── database/
│   ├── migrations/           # Migraciones de base de datos
│   ├── factories/            # Factories para tests
│   └── seeders/              # Seeders de datos iniciales
├── routes/
│   ├── api.php               # Rutas API (protegidas con Sanctum)
│   └── web.php               # Ruta web (SPA shell)
└── storage/
    └── api-docs/             # JSON/YAML generado por Swagger
```

---

## Contribuir

1. Crear rama desde `main`
2. Seguir las convenciones de Laravel (PSR-12 via Pint)
3. Añadir atributos Swagger al controlador nuevo
4. Ejecutar `php artisan test` antes de hacer push
5. Regenerar docs: `php artisan l5-swagger:generate`

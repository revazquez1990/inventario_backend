# Inventario — Backend (API)

API REST para el sistema de **Inventario**: gestión de productos, categorías, unidades,
atributos, proveedores, movimientos de stock (entradas, salidas, ventas, ajustes),
tasa de cambio, reportes y administración de usuarios.

Construido con **Laravel 11** y autenticación **JWT**. Es consumido por el frontend
React (`inventario_frontend`).

---

## Stack

- **PHP** ^8.2
- **Laravel** ^11.31
- **Autenticación:** JWT (`php-open-source-saver/jwt-auth`)
- **Base de datos:** MySQL / MariaDB (probado con MySQL 8.3)
- **Almacenamiento de imágenes:** disco `public` (`storage/app/public`)

---

## Requisitos

- PHP 8.2+ con las extensiones típicas de Laravel (`pdo_mysql`, `mbstring`, `openssl`,
  `fileinfo`, `gd` o `imagick` para imágenes, etc.)
- Composer
- MySQL o MariaDB
- (Opcional) WAMP/XAMPP/Laravel Herd para entorno local en Windows

---

## Instalación

```bash
# 1. Clonar
git clone https://github.com/revazquez1990/inventario_backend.git
cd inventario_backend

# 2. Dependencias
composer install

# 3. Variables de entorno
cp .env.example .env        # en Windows: copy .env.example .env
php artisan key:generate
php artisan jwt:secret       # genera JWT_SECRET en el .env
```

Edita el `.env` y configura la base de datos (la plantilla viene en SQLite; cámbiala a MySQL):

```env
APP_NAME=Inventario
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventario
DB_USERNAME=root
DB_PASSWORD=
```

```bash
# 4. Crear la base de datos 'inventario' en tu motor MySQL, luego:
php artisan migrate --seed

# 5. Enlace del almacenamiento público (para servir imágenes en /storage)
php artisan storage:link

# 6. Levantar el servidor de desarrollo
php artisan serve            # http://localhost:8000
```

> **Importación de datos existentes:** si tienes un volcado SQL (`inventario.sql`), puedes
> importarlo en lugar de correr `migrate --seed`.

---

## Usuarios de prueba (seeder)

| Rol         | Email                        | Contraseña  |
|-------------|------------------------------|-------------|
| admin       | `admin@inventario.local`     | `admin123`  |
| almacenero  | `almacenero@inventario.local`| `almacen123`|

> Los endpoints marcados como **admin** requieren el rol `admin`.

---

## Autenticación

API stateless con **JWT**. Flujo:

1. `POST /api/v1/auth/login` con `{ "email", "password" }` → devuelve el token.
2. Enviar el token en cada petición protegida:
   ```
   Authorization: Bearer <token>
   ```
3. `POST /api/v1/auth/refresh` para renovar y `POST /api/v1/auth/logout` para invalidar.

Middlewares usados: `auth:api`, `active_user` (usuario activo) y `role:admin`.

---

## Endpoints

Prefijo base: **`/api/v1`**

### Salud
- `GET /health` — estado del servicio (público)

### Auth
- `POST /auth/login`
- `POST /auth/refresh`
- `POST /auth/logout` 🔒
- `GET  /auth/me` 🔒

### Usuarios 🔒 admin
- `GET /users`, `POST /users`, `GET /users/{user}`, `PUT /users/{user}`
- `PATCH /users/{user}/status`

### Catálogo 🔒
- **Categorías:** `GET/POST /categories`, `GET/PUT /categories/{category}`, `DELETE` (admin)
- **Unidades:** `GET /units`, `POST/PUT/DELETE` (admin)
- **Atributos:** `GET /attributes` (+ `POST/PUT/DELETE` admin) y `…/{attribute}/values`
- **Proveedores:** `GET/POST /suppliers`, `GET/PUT /suppliers/{supplier}`, `DELETE` (admin)

### Productos 🔒
- `GET /products`, `POST /products`
- `GET /products/{product}`, `PUT|POST /products/{product}`, `DELETE` (admin)
- `GET /products/{product}/movements`
- `GET /products/import-template`, `POST /products/import/preview`, `POST /products/import`

> La creación/edición acepta `multipart/form-data` con el campo `image` (máx. 5 MB).
> En las respuestas, `image_url` es una **ruta relativa** (`/storage/products/…`).

### Configuración 🔒
- **Tasa de cambio:** `GET /exchange-rate/today`, `GET /exchange-rate`, `POST /exchange-rate`
- **Impuesto:** `GET /settings/tax-rate`, `PUT /settings/tax-rate` (admin)
- **Negocio:** `GET /settings/business`, `PUT /settings/business` (admin)

### Movimientos de stock 🔒
- `GET /movements`, `GET /movements/{movement}`
- `POST /movements/entrada`, `/salida`, `/venta`
- `POST /movements/ajuste` (admin)
- `POST /movements/{movement}/anular`

### Reportes / Dashboard 🔒
- `GET /dashboard/kpis`, `GET /dashboard/sales`
- `GET /reports/low-stock`, `GET /reports/movements`

🔒 = requiere `Authorization: Bearer <token>`

---

## Imágenes y almacenamiento

Las imágenes de productos se guardan en `storage/app/public/products` y se sirven vía el
symlink `public/storage` (creado con `php artisan storage:link`). El campo `image_url` de la
API es **relativo** (`/storage/...`) para funcionar en cualquier origen/host sin depender de
`APP_URL`.

---

## Despliegue en red local (LAN / WiFi)

El frontend y la API se sirven en el **mismo origen** usando un reverse proxy de Apache que
reenvía `/api` y `/storage` al backend (así se evita CORS). El paso a paso completo para
montarlo en otra máquina está documentado en el runbook de migración
(`inventario-migracion/RUNBOOK.md`), que también incluye cómo importar la base de datos y
configurar los virtual hosts.

---

## Pruebas

```bash
php artisan test
```

---

## Notas

- **No** se versiona el archivo `.env` (contiene `APP_KEY`, `JWT_SECRET` y credenciales).
- **No** se versiona `vendor/` ni la base de datos; usa `composer install` y un volcado SQL.
- CORS: configurado en `config/cors.php`. Para acceso por IP en LAN, agrega el origen
  correspondiente a `allowed_origins` (o sírvelo en el mismo origen vía proxy).

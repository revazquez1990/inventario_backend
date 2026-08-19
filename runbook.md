# Runbook — Sistema de Inventario Multi-Almacén

> Documento de contexto para retomar el proyecto rápidamente al iniciar una sesión.
> Última actualización: 2026-07-31.

## 1. Qué es el proyecto

Sistema de inventario de productos para una empresa con **varios almacenes y tiendas**.
Regla de negocio central: **cada almacenero solo puede ver y modificar los productos/stock
del/los almacén(es) que tiene asignado(s)**. El administrador ve y controla todo (almacenes,
tiendas, usuarios, catálogos, ajustes).

Consta de dos repos separados:

| Parte | Ruta | Stack |
|-------|------|-------|
| **Frontend** | `C:\Users\revaz\OneDrive\Documents\Work\inventario_project\code\inventario-frontend` | React 19 + TypeScript + Vite 8 + Tailwind 4 |
| **Backend** | `C:\wamp64\www\inventario-backend` (aquí) | Laravel 11 + PHP 8.2 + JWT + MySQL/MariaDB |

## 2. Modelo de dominio

**Ubicaciones (`warehouse`)** — tienen `kind`: `almacen` o `tienda`.
- Los **almaceneros** solo acceden a **almacenes** que se les asignan explícitamente
  (tabla pivote `user_warehouse`). Las **tiendas** son invisibles para ellos.
- El **admin** accede implícitamente a todos los almacenes y tiendas activos.

**Entidades principales (`app/Models`):**
- `Product` — catálogo global. Precio base + variantes (`ProductVariant`) por atributos.
- `Stock` (`product_warehouse`) — cantidad por producto y ubicación; las **tiendas** además
  tienen `sale_price` (precio de venta) por producto.
- `Movement` + `MovementItem` — tipos: `entrada`, `salida`, `venta`, `ajuste`,
  `transferencia`, `anulacion`. Estado `activo`/`anulado`. Guardan snapshots de
  `exchange_rate` y `tax_rate`. Numerados vía `MovementCounter`.
- Catálogos: `Category`, `Unit`, `Attribute`/`AttributeValue`, `Supplier`.
- `ExchangeRate` (USD↔CUP, tasa del día), `Setting` (tax_rate, datos del negocio).
- `User` — roles: `admin` | `almacenero`; estados: `active` | `inactive` | `deleted`
  (enums `App\Enums\UserRole`, `EntityStatus`, `WarehouseKind`).

## 3. Cómo funciona el scoping por almacén (lo más importante)

El frontend envía en **cada request** el header **`X-Warehouse-Id`**. El middleware
`ResolveWarehouse` (`app/Http/Middleware/ResolveWarehouse.php`) lo interpreta:

- **id numérico** → una ubicación concreta (usado para escrituras).
- **`all`** → todos los almacenes agregados (**solo admin**).
- **`all-tiendas`** → todas las tiendas agregadas (**solo admin**).
- **sin header** → admin: todos los almacenes; almacenero: su primer almacén.

Expone a los controladores dos atributos de request: `warehouse_ids` (int[], para
lecturas/agregados) y `warehouse_id` (int|null, la ubicación concreta para escrituras).

Autorización en `App\Models\User`: `accessibleWarehouseIds($kind)`, `canAccessWarehouse($id)`,
`isAdmin()`. Los almaceneros nunca reciben ubicaciones de tipo `tienda`.

Middlewares relacionados (`app/Http/Middleware`): `ResolveWarehouse` (alias `warehouse`),
`EnsureUserHasRole` (alias `role:...`), `EnsureUserIsActive` (alias `active_user`).

## 4. Autenticación

- **JWT** (`php-open-source-saver/jwt-auth`). Login devuelve `token` + `refresh_token` + `user`.
- Guard `auth:api`. Custom claims incluyen `role` (ver `User::getJWTCustomClaims`).
- `AuthController`: `login`, `refresh`, `logout`, `me`.
- Cadena de middleware típica: `auth:api` + `active_user` + (`role:admin` | `warehouse`).

## 5. Rutas API — `routes/api.php`, prefijo `/api/v1`

- `auth/login`, `auth/refresh`, `auth/logout`, `auth/me`, `/health`.
- **Solo admin (`role:admin`):** `/users` (CRUD + `PATCH status`), `/warehouses`
  (CRUD, `GET /{w}/products`, `PUT /{w}/products/{p}/price`).
- **Con scope de almacén (`warehouse` middleware):** `/categories`, `/units`,
  `/attributes` (+`/values`), `/suppliers`, `/products` (+`/import`, `/import/preview`,
  `/import-template`, `GET /{p}/movements`, show/update/delete), `/exchange-rate`,
  `/settings/tax-rate`, `/settings/business`.
- **Movimientos:** `POST /movements/entrada|salida|venta|transferencia`,
  `/ajuste` (solo admin), `POST /movements/{m}/anular`, `GET /movements`, `GET /movements/{m}`.
- **Reportes:** `/dashboard/kpis`, `/dashboard/sales`, `/reports/low-stock`,
  `/reports/movements`, `/reports/product-exits`.
- Varias operaciones de borrado/edición de catálogo (units, attributes, delete de
  category/supplier/product) requieren `role:admin`.

**Controladores (`app/Http/Controllers/Api/V1`):** `AuthController`, `UserController`,
`WarehouseController`, `CatalogController`, `ProductController`, `MovementController`,
`ConfigController`, `ReportController`.

## 6. Cómo levantar el entorno

**Backend** (aquí, `C:\wamp64\www\inventario-backend`, WAMP):
```bash
composer install
cp .env.example .env        # configurar DB MySQL/MariaDB
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed
php artisan serve           # o vía WAMP en http://localhost/inventario-backend/public
```

Otros: `php artisan test` (PHPUnit, `phpunit.xml`), `./vendor/bin/pint` (formato).
Imágenes en disco `public` → `php artisan storage:link`.

**Frontend:**
```bash
npm install
# .env: VITE_API_BASE_URL=/api/v1 (o URL absoluta del backend), VITE_APP_NAME=Inventario
npm run dev
```

## 7. Credenciales de desarrollo (seeders)

`DatabaseSeeder` llama a: Warehouse, User, Setting, Unit, Attribute, Supplier,
MovementCounter seeders.

- **Admin:** `admin@inventario.local` / `admin123`
- **Almacenero:** `almacenero@inventario.local` / `almacen123` (asignado a almacén `PRINCIPAL`)

**Almacenes/tiendas sembrados:** `PRINCIPAL` (Almacén Guanabacoa), `SECUNDARIO`
(Almacén Alamar), `TIENDA-CENTRO` (Tienda Centro, kind=tienda).
**Settings por defecto:** `tax_rate=12.00`, `business_name=Mi Negocio`.

## 8. Migraciones clave (`database/migrations`)

Base (2026_05_21): user, category, unit, attribute(+value), supplier, product,
exchange_rate, setting, movement_counter, movement(+item).
Multi-almacén (2026_06): `product_attribute_value`, `warehouse`, `product_warehouse`,
`user_warehouse`, `add_warehouse_to_movement`, `add_kind_to_warehouse`,
`add_sale_price_to_product_warehouse`.

## 9. Notas / convenciones

- Divisas: precios en **USD** con conversión a **CUP** vía tasa del día; los movimientos
  guardan snapshot de tasa e impuesto para no alterar históricos.
- Respuestas de error del API: `{ error: { code, message, details? } }`.
- Paginación estándar: `{ data, meta: { total, page, per_page, last_page } }`.
- Tablas en **singular** (`user`, `product`, `warehouse`, `movement`, ...) — ver `$table`
  en cada modelo.
- Trait `HasEntityStatus` (`app/Models/Concerns`) para estados y scope `onlyActive()`.
- Hay un archivo `bash.exe.stackdump` sin trackear (ruido, ignorable) y `graphify-out/`
  (salida de la skill graphify).

## 10. Estado / trabajo reciente (foco: tiendas)

- Nombre del Excel de salidas según periodo (semanal/mensual/anual/rango).
- Reportes: salidas por producto con selector de periodo (export Excel).
- Tiendas: selector agrupado, página Tiendas con precios y transferencia con precio de venta.
- Soporte multi-almacén: selector, transferencias y asignación por usuario.

## 11. Fase 4 — Configurar una laptop como NODO (procedimiento)

Central = `https://inventario.portalremesero.com` (staging). Primer nodo configurado
(2026-08-19): esta laptop de dev = **GUANABACOA** (opera el almacén `PRINCIPAL`).

**A) En el central (por SSH, una vez por laptop):**
1. `php artisan sync:node:create GUANABACOA "Almacen Guanabacoa"` → **guardar el token** (solo se muestra una vez).
2. Poner `warehouse.node_id` = el `SYNC_NODE_ID` en el/los almacén(es) de esa laptop:
   `UPDATE warehouse SET node_id='GUANABACOA' WHERE code='PRINCIPAL';`
   (así `incomingTransfersFor()` enruta las transferencias entrantes al nodo).
3. Verificar que existan en el central: el/los almacén(es), el usuario almacenero
   **asignado** (pivote `user_warehouse`), settings y la **tasa de cambio** del día
   (`exchange_rate` es `down`; sin ella el nodo no puede registrar ventas).

**B) En la laptop (`.env` del backend):**
```
SYNC_NODE_ID=GUANABACOA
SYNC_ROLE=node
SYNC_CODE_PREFIX=GUA
SYNC_CENTRAL_URL=https://inventario.portalremesero.com
SYNC_NODE_TOKEN=<token del paso A1>
```
Luego `php artisan config:clear`.

**C) Certificados SSL del PHP CLI (Windows/WAMP) — imprescindible:**
El PHP CLI no trae bundle CA → `sync:run` falla con `cURL error 60`. En el `php.ini`
del CLI (`php --ini`) apuntar a un `cacert.pem` (WAMP trae `C:\wamp64\bin\php\cacert.pem`):
```
curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
openssl.cafile="C:\wamp64\bin\php\cacert.pem"
```

**D) Arrancar limpio y primera bajada (sin usuario, por CLI):**
```
php artisan migrate:fresh --force   # SIN --seed (los almacenes/usuarios bajan del central)
php artisan sync:run --pull
```
Tras el pull: usuarios (con hash → login offline), almacenes, catálogo y settings quedan
locales. El almacenero entra al frontend local con sus credenciales del central.

**Bugs del motor de sync detectados y corregidos (2026-08-19), en `SyncEngine.php`:**
- *Usuarios no bajaban* (`SQLSTATE 1364: password no tiene default`): el alta hacía INSERT
  sin `password` (NOT NULL) antes de escribir el hash crudo. Fix: placeholder en el alta,
  sobrescrito luego con el hash real (bypass del cast `hashed`). **Pendiente commitear y
  desplegar a central + demás laptops.**
- *Riesgo:* `SyncClient::pull` avanza el cursor aunque el import falle → los registros que
  fallan se saltan para siempre. Recuperación puntual: borrar `sync_state.pull_seq:*` y
  re-pull (idempotente por uuid). Convendría avanzar el cursor solo hasta lo aplicado.

**Nota:** hoy la auto-sync la dispara solo el navegador (cada 5 min + evento `online`). Para
sync de fondo con el navegador cerrado, agendar `sync:run` en el scheduler + tarea de Windows.

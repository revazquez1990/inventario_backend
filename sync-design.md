# Diseño — Sincronización offline-first con servidor central

> Diseño acordado para publicar el Inventario en internet manteniendo trabajo local
> offline en cada almacén y sincronización con un servidor central.
> Última actualización: 2026-07-31. Ver también `runbook.md`.

## Contexto y decisiones tomadas

Hoy cada almacén corre en una laptop con su propio Laravel+MySQL, aislado. Se quiere
centralizar la información en internet, pero la conexión en los almacenes es limitada, así
que se debe poder **trabajar 100% offline y subir después**.

Decisiones confirmadas con el cliente:
1. **Arquitectura:** mantener el stack local completo en cada laptop + motor de sync.
2. **Catálogo:** los almaceneros también crean productos localmente (requiere UUIDs y dedup).
3. **Disparo de sync:** automático al detectar internet **+** botón manual "Sincronizar ahora".
4. **Datos actuales:** se arranca limpio (sin migración histórica de las laptops).

## Arquitectura

- **Central** = hub en internet; agrega todo y es la referencia de catálogo/config.
- **Cada laptop = un "nodo"** con Laravel+MySQL propio, trabaja offline.
- **Baja del central** (pull): usuarios, almacenes/tiendas, tasa de cambio, settings, catálogo consolidado.
- **Sube al central** (push): movimientos y productos creados localmente.
- **Sync:** automática al detectar internet **+** botón manual "Sincronizar ahora".

```
Laptop GUANABACOA (Laravel+MySQL) <--sync-->
                                              SERVIDOR CENTRAL (Laravel+MySQL)
Laptop ALAMAR     (Laravel+MySQL) <--sync-->
```

## Por qué el problema es tratable

El sistema ya está **particionado por almacén**: cada almacenero solo toca stock/movimientos
de su almacén, y los movimientos son **append-only** (se crean y a lo sumo se anulan, no se
editan). Por tanto **dos nodos nunca escriben sobre el mismo registro** → no hay conflictos
de escritura clásicos. Quedan 3 puntos a resolver con cuidado (abajo).

## Pieza 1 — Identidad global (IDs que no chocan)

- Cada nodo tiene un `SYNC_NODE_ID` (ej. `GUANABACOA`, `ALAMAR`, `CENTRAL`).
- Cada tabla sincronizable gana `uuid` (único) + `origin_node_id`.
- El **id entero local** se mantiene para las FKs internas; **el `uuid` es la identidad
  real entre nodos**. Al importar, se traduce `uuid → id local` (creando si falta).
- **Códigos legibles namespaced por nodo:** movimientos `GUA-ENT-000123`, productos con
  prefijo de nodo. El `MovementCounter` pasa a ser **por nodo** → nunca colisiona.

## Pieza 2 — Catálogo creado en varias laptops (dedup)

- **Dedup técnico (automático):** nunca se importa dos veces el mismo `uuid`.
- **Dedup de negocio (manual):** dos personas crean "el mismo" producto con distinto uuid →
  duplicado real que la máquina no puede adivinar. Se resuelve con una **pantalla en el
  central para fusionar productos** (mapear producto X → Y y reapuntar sus movimientos).

## Pieza 3 — Transferencias entre almacenes

- Node A crea la transferencia → stock baja en A al instante (offline).
- Sube al central y baja al nodo B; en B queda **"en tránsito"**.
- El almacenero de B **confirma la recepción** → entra el stock en B.
- Estados nuevos en la transferencia: `en_transito` | `recibido`.

## Motor de sincronización

- **Idempotente por `uuid`:** reenviar dos veces no duplica (clave con cortes de internet).
- **Cursores/watermarks:** el central asigna una secuencia creciente (`server_seq`) a cada
  registro aceptado; cada nodo pide "todo lo que tenga `server_seq` > mi último cursor".
- **Push:** el nodo envía sus cambios locales no sincronizados (upsert por uuid en central),
  el central acusa recibo, el nodo los marca como sincronizados.
- **Auth de nodo:** token de máquina por laptop, separado del JWT de usuarios.
- **Detección de conexión:** ping a `/api/v1/health`; si responde, auto-sync; + botón manual.
- **Dirección por entidad:**
  - Baja (central→nodo): usuarios, warehouses, user_warehouse, exchange_rate, settings,
    product_warehouse.sale_price (config de tienda).
  - Sube (nodo→central): movements, movement_items, productos y catálogo creados localmente.
  - Bidireccional consolidado: catálogo (products, categories, units, attributes,
    attribute_values, suppliers) — sube lo local, baja lo consolidado.
  - **Stock NO se sincroniza como filas**: es estado derivado; cada lado recalcula el stock
    de su almacén a partir de los movimientos que tiene.

## Tablas sincronizables (referencia del esquema)

- **Catálogo (bidireccional):** `category`, `unit`, `attribute`, `attribute_value`,
  `supplier`, `product`, `product_attribute_value`.
- **Config/maestros (baja central→nodo):** `user`, `warehouse`, `user_warehouse`,
  `exchange_rate`, `setting`.
- **Operación (sube nodo→central):** `movement`, `movement_item`.
- **Derivado (no se sincroniza como filas):** `product_warehouse` (stock) — pero
  `sale_price` de tienda sí baja como config.
- **Local por nodo (no sincroniza):** `movement_counter` (pasa a ser por nodo).

## Plan por fases

| Fase | Qué | Resultado |
|------|-----|-----------|
| **0. Identidad** | `uuid` + `origin_node_id` en tablas sincronizables, `config/sync.php`, `MovementCounter`/códigos por nodo, backfill | Base lista, sin cambiar el comportamiento actual |
| **1. Motor de sync** | Endpoints `/sync/push` y `/sync/pull` en central, servicio+comando en el nodo, upsert por uuid, auth de nodo, cursores | Sincroniza catálogo (baja) y movimientos/productos (sube) |
| **2. Transferencias** | Estado `en_transito`/`recibido` + confirmar recepción en destino | Transferencias entre almacenes correctas |
| **3. Frontend** | Indicador de estado (pendientes, última sync), botón manual, auto al detectar internet, pantalla de fusión de duplicados | Experiencia completa almacenero + admin |
| **4. Despliegue** | Central en VPS con HTTPS y backups; cada laptop configurada como nodo | En producción |

## Estado de avance

- [x] **Fase 0 — Identidad** (implementada 2026-07-31)
- [x] **Fase 1 — Motor de sync** (implementada 2026-07-31)
- [x] **Fase 1.1 — Pivotes, settings y stock** (implementada 2026-07-31)
- [x] **Fase 2 — Transferencias** (implementada 2026-07-31)
- [x] **Fase 3 — Frontend** (implementada 2026-07-31)
- [ ] Fase 4 — Despliegue

## Fase 0 — Implementado

Archivos:
- `config/sync.php` — config del nodo: `node_id`, `role`, `code_prefix`, `central_url`, `node_token`.
- `.env.example` — variables `SYNC_NODE_ID`, `SYNC_ROLE`, `SYNC_CODE_PREFIX`, `SYNC_CENTRAL_URL`, `SYNC_NODE_TOKEN`.
- `app/Models/Concerns/HasSyncIdentity.php` — trait que asigna `uuid` + `origin_node_id` al crear.
- `database/migrations/2026_07_31_000001_add_sync_identity_columns.php` — añade `uuid` (único)
  + `origin_node_id` a: category, unit, attribute, attribute_value, supplier, product,
  warehouse, user, exchange_rate, movement, movement_item; backfill portable (MySQL/SQLite);
  ensancha `movement.code` a 30.
- `app/Services/MovementCodeGenerator.php` — antepone el prefijo de nodo a los códigos.
- Trait aplicado a los 11 modelos sincronizables.
- `tests/Feature/SyncIdentityTest.php` — verifica uuid/origin y prefijo de código.

Config por instalación (en cada `.env`):
- **Central:** `SYNC_NODE_ID=CENTRAL`, `SYNC_ROLE=central`, `SYNC_CODE_PREFIX=CEN`.
- **Cada laptop:** `SYNC_NODE_ID=GUANABACOA` (único), `SYNC_ROLE=node`,
  `SYNC_CODE_PREFIX=GUA` (único, corto), `SYNC_CENTRAL_URL` y `SYNC_NODE_TOKEN` (desde Fase 1).

Para aplicar en una instalación existente: `php artisan migrate` (con MySQL levantado).
Estado de tests tras Fase 0: 16 previos siguen pasando + 3 nuevos; los 2 fallos de
`MvpFlowTest` (variantes de producto) son **preexistentes** y ajenos a esta fase.

## Fase 1 — Implementado

Infraestructura (`database/migrations/2026_07_31_000002_create_sync_tables.php`):
- `sync_node` (central): nodos autorizados + hash del token de máquina + `last_seen_at`.
- `sync_sequence` (central): secuencia global monotónica; su valor es el cursor `sync_seq`.
- `sync_state` (nodo): cursores por entidad de la última bajada (`pull_seq:<entidad>`).
- `sync_outbox` (nodo): cola de registros locales pendientes de subir.
- Columna `sync_seq` en las 11 tablas sincronizables (+ backfill de lo existente).

Motor y API:
- `config/sync.php` → `entities`: registro de las 11 entidades (columnas, relaciones por
  uuid, dirección up/down/both). Orden = dependencias primero.
- `app/Services/Sync/SyncEngine.php` — serialize/import; traduce FKs `uuid ↔ id local`;
  upsert idempotente por uuid; `changesFor()` por cursor.
- `app/Models/Concerns/HasSyncIdentity.php` (ampliado) — en el central estampa `sync_seq`
  en cada escritura; en los nodos encola los cambios de origen local al outbox.
- `app/Http/Middleware/AuthenticateSyncNode.php` (alias `sync.node`) — auth por
  `X-Sync-Node` + `X-Sync-Token`.
- `app/Http/Controllers/Api/V1/SyncController.php` — `POST /api/v1/sync/pull` y `/push`.
  Pull excluye lo originado por el propio nodo (evita eco).
- `app/Services/Sync/SyncClient.php` — cliente HTTP del nodo (push desde outbox, pull con
  cursores).
- Comandos: `sync:run` (nodo; `--push`/`--pull`), `sync:node:create {node_id} {name?}`
  (central; genera y muestra el token una vez).
- `tests/Feature/SyncEngineTest.php` — auth 401, push con resolución de FKs + idempotencia,
  pull con exclusión de origen. Suite: 22 pasan + los 2 preexistentes de variantes.

Puesta en marcha:
1. **Central:** `SYNC_ROLE=central`, `SYNC_NODE_ID=CENTRAL`; `php artisan migrate --seed`;
   por cada laptop: `php artisan sync:node:create GUANABACOA "Almacén Guanabacoa"` → copiar el token.
2. **Cada nodo:** `SYNC_ROLE=node`, `SYNC_NODE_ID=GUANABACOA`, `SYNC_CODE_PREFIX=GUA`,
   `SYNC_CENTRAL_URL=https://central...`, `SYNC_NODE_TOKEN=<token>`; `php artisan migrate`
   (**sin** `--seed`: los maestros bajan del central); primera sync online: `php artisan sync:run`.
3. Automatizar: programar `sync:run` (scheduler/cron) y/o exponerlo desde el frontend (Fase 3).

## Fase 1.1 — Implementado

- **Pivotes embebidos en el padre** (viajan como lista de uuids):
  `user_warehouse` → `warehouse_uuids` en `user` (baja); `product_attribute_value` →
  `attribute_value_uuids` en `product` (ambos). Config `entities[*].links`; serialize/import
  en `SyncEngine`. Los controladores hacen `touch()` del padre al cambiar el pivote para
  que el `sync_seq`/outbox lo detecte.
- **`setting`**: snapshot completo en la bajada (`data.setting`), el nodo hace upsert por `key`.
- **`product_warehouse` (stock + sale_price)**: ahora es entidad sincronizable `up` con
  `uuid`/`origin_node_id`/`sync_seq` (migración `..._000003`), `Stock` usa `HasSyncIdentity`.
  Se sube directo (un único escritor por almacén → sin conflicto). Nota: recomputar el stock
  en el central desde los movimientos NO es viable porque en los ajustes el signo se pierde
  (`movement_item.quantity` se guarda en absoluto); por eso se sincroniza el stock tal cual.
- Tests: `tests/Feature/SyncLinksTest.php`.

## Fase 2 — Implementado

Transferencias en dos fases (migración `..._000004`):
- Campos nuevos: `movement.transfer_status` (`en_transito`/`recibido`), `movement.received_at`,
  `movement.received_by_user_id`, `movement_item.sale_price`, `warehouse.node_id`.
- Enum `App\Enums\TransferStatus`.
- `MovementService`: al crear una transferencia el stock **solo sale del origen**
  (queda `en_transito`); `confirmReception()` suma el stock en el destino (con el
  `sale_price` previsto si es tienda) y marca `recibido`. La anulación devuelve al origen
  y solo descuenta del destino si ya se había recibido.
- `MovementController`: `transferencia` ya no exige acceso al destino (puede estar en otro
  nodo); nuevo endpoint `POST /movements/{id}/recibir` (lo confirma quien opera el destino
  o un admin). `transfer_status`/`received_*` en la serialización.
- **Entrega entre nodos:** `SyncEngine::incomingTransfersFor($node)` — el pull entrega a
  cada nodo las transferencias `en_transito` cuyo destino es un almacén suyo
  (`warehouse.node_id`) originadas en otro nodo. Al recibirlas, `confirmReception` encola
  el movimiento al outbox aunque su origen sea ajeno, para que la recepción suba al central.
  **Importante:** el orden en `sync:run` es push→pull, para que la recepción suba antes de
  volver a bajar la lista de entrantes (evita pisar `recibido` con `en_transito`).
- Tests: `tests/Feature/TransferReceptionTest.php`; `WarehouseFlowTest`/`StoreFlowTest`
  actualizados al flujo de dos fases. Suite: 29 pasan + los 2 preexistentes de variantes.

Config extra por almacén: en el central, asigna `warehouse.node_id` a cada almacén con el
id del nodo que lo opera (para enrutar las transferencias entrantes).

## Fase 3 — Implementado

Backend (endpoints locales del nodo, auth por JWT de usuario, no por token de máquina):
- `GET /api/v1/sync/status` — role, node_id, si el central está configurado, pendientes de
  subir (total y por entidad), última sync.
- `POST /api/v1/sync/run` — ejecuta push+pull vía `SyncClient` (solo si role=node).
- `SyncLocalController`. `warehouse.node_id` ahora se valida y serializa en `WarehouseController`.

Frontend (`inventario-frontend`):
- `src/features/sync/sync.api.ts` + `SyncStatusButton.tsx` — indicador en el header:
  pendientes por subir, última sync, botón "Sincronizar ahora"; auto-sync al recuperar
  internet (evento `online`) y cada 5 min. Solo visible cuando role=node.
- Movimientos: badge `en_transito`/`recibido`, línea origen→destino y acción **Recibir**
  (POST `/movements/{id}/recibir`) para transferencias en tránsito.
- Almacenes: campo `node_id` (qué nodo opera el almacén).
- Verificado con `tsc -b` + `vite build`.

## Fase 3.2 — Sincronización de imágenes (archivos)

El sync viaja como filas (columnas por uuid): `product.image` lleva solo el **path**, no el
archivo. Para que las imágenes se vean en el central se sincroniza también el binario:
- **Central:** `GET /api/v1/sync/media/missing` devuelve los `product.image` referenciados en
  BD cuyo archivo falta en disco; `POST /api/v1/sync/media` (multipart `path`+`file`) lo guarda
  en `storage/app/public/<path>` si no existe (idempotente; solo rutas dentro de `products/`).
  Ambas bajo `sync.node`.
- **Nodo:** `SyncClient::pushMedia()` pide la lista de faltantes y sube las que tenga localmente.
  Se dispara tras el push en `sync:run` y en `POST /sync/run` (respuesta incluye `media`).
- Pendiente (futuro): bajar al nodo las imágenes de productos de **otros** nodos (hoy solo
  sube nodo→central, que es lo que necesita el central para mostrarlas).

## Notas / riesgos

- Hacer cada fase incremental y probada antes de la siguiente; no todo de una vez.
- Cuidado con las FKs al traducir `uuid → id local` en el import (orden de dependencias:
  category/unit/attribute/supplier antes que product; product antes que movement_item).
- `product.code` y `movement.code` deben quedar namespaced por nodo para no colisionar.
- Password hashes de usuarios viajan en la bajada para permitir login offline en el nodo.

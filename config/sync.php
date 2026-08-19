<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad del nodo
    |--------------------------------------------------------------------------
    |
    | Id único de esta instalación dentro de la red de sincronización.
    | Usar 'CENTRAL' en el servidor central y un id único por laptop de
    | almacén (p. ej. 'GUANABACOA', 'ALAMAR'). Se estampa en cada registro
    | creado localmente (columna `origin_node_id`).
    |
    */

    'node_id' => env('SYNC_NODE_ID', 'CENTRAL'),

    /*
    |--------------------------------------------------------------------------
    | Rol de la instalación
    |--------------------------------------------------------------------------
    |
    | 'central' = servidor hub que agrega y consolida.
    | 'node'    = laptop de almacén que trabaja offline y sincroniza contra el central.
    |
    */

    'role' => env('SYNC_ROLE', 'central'),

    /*
    |--------------------------------------------------------------------------
    | Prefijo de códigos legibles
    |--------------------------------------------------------------------------
    |
    | Prefijo corto (<= 6 caracteres, único por nodo) que se antepone a los
    | códigos de movimiento para que no colisionen al consolidarse en el central.
    | Ej.: 'GUA' -> GUA-E-00001.
    |
    */

    'code_prefix' => env('SYNC_CODE_PREFIX', 'CEN'),

    /*
    |--------------------------------------------------------------------------
    | Conexión con el central (solo para nodos)
    |--------------------------------------------------------------------------
    |
    | URL base del API del servidor central y token de máquina con el que este
    | nodo se autentica al sincronizar (independiente del JWT de los usuarios).
    | Se usarán a partir de la Fase 1 (motor de sincronización).
    |
    */

    'central_url' => env('SYNC_CENTRAL_URL'),
    'node_token' => env('SYNC_NODE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Registro de entidades sincronizables
    |--------------------------------------------------------------------------
    |
    | El motor de sincronización recorre estas entidades en orden (importante:
    | las dependencias van primero). Para cada una:
    |
    |   'model'      Modelo Eloquent (la clave del array es el nombre de tabla).
    |   'columns'    Columnas escalares propias que viajan tal cual.
    |   'relations'  fk_local => entidad relacionada. En el payload viajan como
    |                "<fk>_uuid"; al importar se traduce uuid -> id local.
    |   'direction'  Desde la óptica del nodo:
    |                  'down' = el nodo solo la baja del central (maestro central).
    |                  'up'   = el nodo solo la sube (operación local).
    |                  'both' = la sube (origen local) y baja lo consolidado.
    |
    */

    'entities' => [

        'user' => [
            'model' => \App\Models\User::class,
            'columns' => ['name', 'email', 'password', 'role', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            // Asignación usuario<->almacén (pivote user_warehouse) embebida por uuid.
            'links' => [
                'warehouse_uuids' => ['relation' => 'warehouses', 'related' => 'warehouse'],
            ],
            'direction' => 'down',
        ],

        'warehouse' => [
            'model' => \App\Models\Warehouse::class,
            'columns' => ['name', 'kind', 'code', 'node_id', 'address', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            'direction' => 'down',
        ],

        'category' => [
            'model' => \App\Models\Category::class,
            'columns' => ['name', 'description', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            'direction' => 'both',
        ],

        'unit' => [
            'model' => \App\Models\Unit::class,
            'columns' => ['name', 'abbreviation', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            'direction' => 'both',
        ],

        'attribute' => [
            'model' => \App\Models\Attribute::class,
            'columns' => ['name', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            'direction' => 'both',
        ],

        'attribute_value' => [
            'model' => \App\Models\AttributeValue::class,
            'columns' => ['value', 'status', 'created_at', 'updated_at'],
            'relations' => ['attribute_id' => 'attribute'],
            'direction' => 'both',
        ],

        'supplier' => [
            'model' => \App\Models\Supplier::class,
            'columns' => ['name', 'contact_name', 'phone', 'email', 'address', 'notes', 'status', 'created_at', 'updated_at'],
            'relations' => [],
            'direction' => 'both',
        ],

        'product' => [
            'model' => \App\Models\Product::class,
            'columns' => ['code', 'name', 'price', 'reference', 'image', 'status', 'created_at', 'updated_at'],
            'relations' => ['category_id' => 'category', 'unit_id' => 'unit'],
            // Valores de atributo del producto (pivote product_attribute_value) embebidos por uuid.
            'links' => [
                'attribute_value_uuids' => ['relation' => 'attributeValues', 'related' => 'attribute_value'],
            ],
            'direction' => 'both',
        ],

        // Stock por almacén (y sale_price de tiendas). Único escritor por almacén
        // (su nodo), por lo que se sube directamente sin riesgo de conflicto.
        'product_warehouse' => [
            'model' => \App\Models\Stock::class,
            'columns' => ['quantity', 'sale_price', 'created_at', 'updated_at'],
            'relations' => ['product_id' => 'product', 'warehouse_id' => 'warehouse'],
            'direction' => 'up',
        ],

        'exchange_rate' => [
            'model' => \App\Models\ExchangeRate::class,
            'columns' => ['rate_date', 'usd_to_cup', 'created_at', 'updated_at'],
            'relations' => ['created_by_user_id' => 'user'],
            'direction' => 'down',
        ],

        'movement' => [
            'model' => \App\Models\Movement::class,
            'columns' => [
                'type', 'adjustment_subtype', 'code', 'status', 'transfer_status',
                'exchange_rate_snapshot', 'tax_rate_snapshot',
                'reason', 'reason_void',
                'total_without_tax_usd', 'total_tax_usd', 'total_with_tax_usd',
                'total_without_tax_cup', 'total_tax_cup', 'total_with_tax_cup',
                'voided_at', 'received_at', 'created_at', 'updated_at',
            ],
            'relations' => [
                'warehouse_id' => 'warehouse',
                'to_warehouse_id' => 'warehouse',
                'supplier_id' => 'supplier',
                'original_movement_id' => 'movement',
                'exchange_rate_id' => 'exchange_rate',
                'created_by_user_id' => 'user',
                'voided_by_user_id' => 'user',
                'received_by_user_id' => 'user',
            ],
            'direction' => 'up',
        ],

        'movement_item' => [
            'model' => \App\Models\MovementItem::class,
            'columns' => [
                'quantity', 'sale_price',
                'unit_price_with_tax_usd', 'unit_price_with_tax_cup',
                'subtotal_with_tax_usd', 'subtotal_tax_usd', 'subtotal_without_tax_usd',
                'subtotal_with_tax_cup', 'subtotal_tax_cup', 'subtotal_without_tax_cup',
            ],
            'relations' => ['movement_id' => 'movement', 'product_id' => 'product'],
            'direction' => 'up',
        ],

    ],

];

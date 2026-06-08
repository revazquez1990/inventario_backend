<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\MovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ZipArchive;

class ProductController extends Controller
{
    public function __construct(private readonly MovementService $movementService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['category', 'unit', 'attributeValues.attribute'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => $this->serializeProduct($product))
            ->values();

        return response()->json(['data' => $products]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProduct($request);

        if ($request->hasFile('image')) {
            $request->validate(['image' => ['image', 'max:5120']]);
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $initialQty = (int) ($data['quantity'] ?? 0);
        $supplierId = ($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null;
        unset($data['supplier_id']);
        $attributeValueIds = $data['attribute_value_ids'] ?? [];
        unset($data['attribute_value_ids']);

        $data['quantity'] = 0;
        $data['status'] ??= EntityStatus::ACTIVE;

        $product = null;
        DB::transaction(function () use ($data, $initialQty, $supplierId, $attributeValueIds, $request, &$product) {
            $product = Product::query()->create($data);

            $product->attributeValues()->sync($attributeValueIds);

            if ($initialQty > 0) {
                $this->movementService->create(MovementType::ENTRADA, [
                    'supplier_id' => $supplierId,
                    'reason' => 'Stock inicial al crear producto',
                    'items' => [[
                        'product_id' => $product->id,
                        'quantity' => $initialQty,
                        'unit_price_with_tax_usd' => (float) $product->price,
                    ]],
                ], $request->user());
            }
        });

        return response()->json(['data' => $this->serializeProduct($product->refresh()->load(['category', 'unit', 'attributeValues.attribute']))], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->serializeProduct($product->load(['category', 'unit', 'attributeValues.attribute']))]);
    }

    public function movements(Product $product): JsonResponse
    {
        $movements = \App\Models\Movement::query()
            ->with([
                'createdBy:id,name',
                'supplier:id,name',
                'items' => fn ($q) => $q->where('product_id', $product->id),
            ])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->latest()
            ->get()
            ->map(fn (\App\Models\Movement $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'type' => $m->type?->value ?? $m->getRawOriginal('type'),
                'status' => $m->status?->value ?? 'activo',
                'quantity' => (int) $m->items->sum('quantity'),
                'created_at' => $m->created_at?->toDateTimeString(),
                'created_by' => $m->createdBy?->name,
                'supplier' => $m->supplier?->name,
                'reason' => $m->reason,
            ]);

        return response()->json(['data' => $movements]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:60', Rule::unique('product', 'code')->ignore($product->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'category_id' => ['sometimes', 'integer', Rule::exists('category', 'id')],
            'unit_id' => ['sometimes', 'integer', Rule::exists('unit', 'id')],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:160'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'attribute_value_ids' => ['nullable', 'array'],
            'attribute_value_ids.*' => ['integer', Rule::exists('attribute_value', 'id')],
        ]);

        $syncAttributeValues = $request->boolean('manage_variety');
        $attributeValueIds = $data['attribute_value_ids'] ?? [];
        unset($data['attribute_value_ids']);

        if ($request->hasFile('image')) {
            $request->validate(['image' => ['image', 'max:5120']]);
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product->fill($data)->save();

        if ($syncAttributeValues) {
            $product->attributeValues()->sync($attributeValueIds);
        }

        return response()->json(['data' => $this->serializeProduct($product->refresh()->load(['category', 'unit', 'attributeValues.attribute']))]);
    }

    public function delete(Product $product): JsonResponse
    {
        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }
        $product->softDeleteStatus();

        return response()->json(null, 204);
    }

    public function template(Request $request)
    {
        $format = $request->string('format', 'csv')->lower()->toString();
        $unitOptions = Unit::query()->get()->map(fn ($u) => "{$u->abbreviation} ({$u->name})")->join(', ');
        $headers = ['code', 'name', 'category', 'unit', 'price', 'reference', 'quantity'];
        $note    = ['# Unidades válidas ->', $unitOptions, '', '', '', '', ''];
        $example = ['PROD001', 'Camisa Polo', 'Ropa', 'u', '12.50', 'REF-001', '10'];

        if ($format === 'xlsx') {
            return $this->xlsxResponse([$headers, $note, $example], 'plantilla_productos.xlsx');
        }

        $csv = $this->csvString([$headers, $note, $example]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla_productos.csv"',
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        [$headers, $rows] = $this->uploadedRows($request);
        $preview = $this->previewRows($headers, $rows);

        return response()->json(['data' => $preview]);
    }

    public function import(Request $request): JsonResponse
    {
        [$headers, $rows] = $this->uploadedRows($request);
        $preview = $this->previewRows($headers, $rows);

        if ($preview['errors_count'] > 0) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_HAS_ERRORS',
                    'message' => 'El archivo tiene errores. Revísalo antes de confirmar.',
                    'details' => ['rows' => $preview['rows']],
                ],
            ], 422);
        }

        $createdProducts = 0;
        $initialItems = [];

        $allUnits = Unit::query()->get();
        $unitLookup = [];
        foreach ($allUnits as $u) {
            $unitLookup[strtolower($u->abbreviation)] = $u;
            $unitLookup[strtolower($u->name)] = $u;
        }

        DB::transaction(function () use ($preview, &$createdProducts, &$initialItems, $request, $unitLookup) {
            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'valid') {
                    continue;
                }

                $line = $row['data'];
                $unit = $unitLookup[strtolower(trim($line['unit'] ?? ''))] ?? null;
                if (! $unit) {
                    throw new \RuntimeException("Unidad '{$unitValue}' no encontrada.");
                }
                $category = Category::query()->firstOrCreate(['name' => trim($line['category'])], ['status' => EntityStatus::ACTIVE]);
                $product = Product::query()->firstOrCreate(
                    ['code' => trim($line['code'])],
                    [
                        'name' => trim($line['name']),
                        'category_id' => $category->id,
                        'unit_id' => $unit->id,
                        'price' => (float) $line['price'],
                        'reference' => trim((string) ($line['reference'] ?? '')),
                        'quantity' => 0,
                        'status' => EntityStatus::ACTIVE,
                    ],
                );

                if ($product->wasRecentlyCreated) {
                    $createdProducts++;
                }

                $initialQty = (int) ($line['quantity'] ?? 0);
                if ($initialQty > 0) {
                    $initialItems[] = [
                        'product_id' => $product->id,
                        'quantity' => $initialQty,
                        'unit_price_with_tax_usd' => (float) $line['price'],
                    ];
                }
            }

            if ($initialItems !== []) {
                $supplier = Supplier::query()->firstOrCreate(
                    ['name' => 'Inventario Inicial'],
                    ['notes' => 'Proveedor de sistema para registrar el stock inicial al cargar productos por primera vez.'],
                );
                $this->movementService->create(MovementType::ENTRADA, [
                    'supplier_id' => $supplier->id,
                    'reason' => 'Carga inicial por importación',
                    'items' => $initialItems,
                ], $request->user());
            }
        });

        return response()->json([
            'data' => [
                'created_products' => $createdProducts,
                'initial_stock_lines' => count($initialItems),
            ],
        ]);
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('product', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['required', 'integer', Rule::exists('category', 'id')],
            'unit_id' => ['required', 'integer', Rule::exists('unit', 'id')],
            'price' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:160'],
            'quantity' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('supplier', 'id'),
            ],
            'attribute_value_ids' => ['nullable', 'array'],
            'attribute_value_ids.*' => ['integer', Rule::exists('attribute_value', 'id')],
        ]);
    }

    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'category' => $product->category,
            'unit' => $product->unit,
            'price' => $product->price,
            'reference' => $product->reference,
            'quantity' => $product->quantity,
            'image_url' => $product->image ? Storage::disk('public')->url($product->image) : null,
            'status' => $product->status?->value ?? EntityStatus::ACTIVE->value,
            'created_at' => $product->created_at?->toDateTimeString(),
            'attribute_values' => $product->relationLoaded('attributeValues')
                ? $product->attributeValues->map(fn ($value) => [
                    'id' => $value->id,
                    'value' => $value->value,
                    'attribute' => $value->attribute?->name,
                ])->values()
                : [],
        ];
    }

    private function uploadedRows(Request $request): array
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = $extension === 'xlsx'
            ? $this->readXlsxRows($file->getRealPath())
            : $this->readCsvRows($file->getRealPath());

        if (count($rows) < 2) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['error' => ['code' => 'EMPTY_IMPORT', 'message' => 'El archivo no tiene filas para importar.']], 422),
            );
        }

        return [
            array_map(fn ($value) => Str::snake(trim((string) $value)), array_shift($rows)),
            $rows,
        ];
    }

    private function previewRows(array $headers, array $rows): array
    {
        $previewRows = [];
        $valid = 0;
        $errorsCount = 0;
        $seenCodes = [];

        $allUnits = Unit::query()->get();
        $unitIndex = [];
        foreach ($allUnits as $u) {
            $unitIndex[strtolower($u->abbreviation)] = $u->id;
            $unitIndex[strtolower($u->name)] = $u->id;
        }
        $unitHints = $allUnits->map(fn ($u) => "{$u->abbreviation} ({$u->name})")->join(', ');

        foreach ($rows as $index => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $line = array_combine($headers, array_pad($row, count($headers), ''));
            $line = array_map(fn ($value) => trim((string) $value), $line);

            // Strip the note row that the template includes
            if (str_starts_with((string) ($line['code'] ?? ''), '#')) {
                continue;
            }

            $rowErrors = [];
            $rowNumber = $index + 2;

            foreach (['code', 'name', 'category', 'unit', 'price'] as $required) {
                if (($line[$required] ?? '') === '') {
                    $rowErrors[] = "Falta {$required}.";
                }
            }

            $unitValue = $line['unit'] ?? '';
            if ($unitValue !== '' && ! isset($unitIndex[strtolower($unitValue)])) {
                $rowErrors[] = "Unidad '{$unitValue}' no reconocida. Válidas: {$unitHints}.";
            }

            if (($line['code'] ?? '') !== '') {
                if (in_array($line['code'], $seenCodes, true)) {
                    $rowErrors[] = 'Código duplicado en el archivo.';
                }
                $seenCodes[] = $line['code'];
                if (Product::query()->withDeleted()->where('code', $line['code'])->exists()) {
                    $rowErrors[] = 'El código ya existe en el sistema.';
                }
            }

            foreach (['price', 'quantity'] as $numeric) {
                if (($line[$numeric] ?? '') !== '' && ! is_numeric($line[$numeric])) {
                    $rowErrors[] = "{$numeric} debe ser numérico.";
                }
            }

            if (($line['quantity'] ?? '') !== '' && (int) $line['quantity'] < 0) {
                $rowErrors[] = 'quantity no puede ser negativo.';
            }

            $status = $rowErrors === [] ? 'valid' : 'error';
            $valid += $status === 'valid' ? 1 : 0;
            $errorsCount += count($rowErrors);
            $previewRows[] = [
                'row' => $rowNumber,
                'status' => $status,
                'errors' => $rowErrors,
                'data' => $line,
            ];
        }

        return [
            'valid_rows' => $valid,
            'errors_count' => $errorsCount,
            'rows' => $previewRows,
        ];
    }

    private function readCsvRows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        while (($row = fgetcsv($handle ?: throw new \RuntimeException('No se pudo leer el CSV'))) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo leer el XLSX.');
        }
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $sharedStrings = [];
        if ($sharedStringsXml !== false) {
            $xml = simplexml_load_string($sharedStringsXml);
            foreach ($xml->si ?? [] as $si) {
                $sharedStrings[] = (string) ($si->t ?? '');
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $xml = simplexml_load_string($sheetXml ?: throw new \RuntimeException('No se pudo leer la hoja 1.'));
        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                if ((string) $cell['t'] === 'inlineStr') {
                    $values[] = (string) ($cell->is->t ?? '');
                    continue;
                }
                $value = (string) ($cell->v ?? '');
                $values[] = ((string) $cell['t'] === 's') ? ($sharedStrings[(int) $value] ?? '') : $value;
            }
            $rows[] = $values;
        }

        return $rows;
    }

    private function csvString(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function xlsxResponse(array $rows, string $filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Productos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $sheetRows = '';
        foreach ($rows as $rIndex => $row) {
            $sheetRows .= '<row r="'.($rIndex + 1).'">';
            foreach (array_values($row) as $cIndex => $value) {
                $ref = chr(65 + $cIndex).($rIndex + 1);
                $sheetRows .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }
            $sheetRows .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        return response()->download($tmp, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function rowIsEmpty(array $row): bool
    {
        return trim(implode('', array_map('strval', $row))) === '';
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use ZipArchive;

class ReportController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $warehouseId = $this->resolvedWarehouseId($request);
        $date = $request->string('date', now('America/Bogota')->toDateString())->toString();
        $sales = Movement::query()->where('type', 'venta')->where('status', 'activo')->whereDate('created_at', $date)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId));
        $movements = Movement::query()->whereDate('created_at', $date)
            ->when($warehouseId !== null, fn ($q) => $q->where(function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId)->orWhere('to_warehouse_id', $warehouseId);
            }));

        return response()->json([
            'data' => [
                'date' => $date,
                'sales_total_usd' => (float) (clone $sales)->sum('total_with_tax_usd'),
                'sales_total_cup' => (float) (clone $sales)->sum('total_with_tax_cup'),
                'sales_count' => (int) (clone $sales)->count(),
                'movements_count' => (int) $movements->count(),
                'low_stock_count' => $this->lowStockProducts($warehouseId)->count(),
            ],
        ]);
    }

    public function sales(Request $request)
    {
        $warehouseId = $this->resolvedWarehouseId($request);
        $from = $request->string('from', now('America/Bogota')->startOfMonth()->toDateString())->toString();
        $to = $request->string('to', now('America/Bogota')->toDateString())->toString();
        $base = Movement::query()
            ->where('type', 'venta')
            ->where('status', 'activo')
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($request->filled('user_id'), fn ($q) => $q->where('created_by_user_id', $request->integer('user_id')))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        $byPeriod = (clone $base)
            ->selectRaw('DATE(created_at) as label, COUNT(*) as count, SUM(total_with_tax_usd) as total_usd, SUM(total_with_tax_cup) as total_cup')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('label')
            ->get();
        $rows = $byPeriod->map(fn ($row) => [
            'periodo' => $row->label,
            'ventas' => $row->count,
            'total_usd' => $row->total_usd,
            'total_cup' => $row->total_cup,
        ])->all();

        if ($request->filled('format')) {
            return $this->export('reporte_ventas', ['periodo', 'ventas', 'total_usd', 'total_cup'], $rows, $request->string('format')->toString());
        }

        return response()->json([
            'totals' => [
                'sales_count' => (int) (clone $base)->count(),
                'total_usd' => (float) (clone $base)->sum('total_with_tax_usd'),
                'total_cup' => (float) (clone $base)->sum('total_with_tax_cup'),
                'avg_ticket_usd' => round((float) (clone $base)->avg('total_with_tax_usd'), 2),
            ],
            'comparison' => ['prev_total_usd' => 0, 'delta_pct' => 0],
            'by_period' => $byPeriod,
            'top_products' => $this->topProducts($from, $to, $warehouseId),
            'by_user' => $this->byUser($from, $to, $warehouseId),
            'by_category' => $this->byCategory($from, $to, $warehouseId),
        ]);
    }

    public function lowStock(Request $request)
    {
        $rows = $this->lowStockProducts($this->resolvedWarehouseId($request))
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'quantity' => (int) ($product->quantity ?? 0),
                'category' => $product->category?->name,
            ])
            ->values();

        if ($request->filled('format')) {
            return $this->export('productos_sin_stock', ['id', 'code', 'name', 'quantity', 'category'], $rows->all(), $request->string('format')->toString());
        }

        return response()->json(['data' => $rows]);
    }

    public function movements(Request $request, MovementController $controller)
    {
        if ($request->filled('format')) {
            $warehouseId = $this->resolvedWarehouseId($request);
            $rows = Movement::query()
                ->with('createdBy:id,name')
                ->when($warehouseId !== null, fn ($q) => $q->where(function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId)->orWhere('to_warehouse_id', $warehouseId);
                }))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
                ->latest()
                ->limit(1000)
                ->get()
                ->map(fn (Movement $movement) => [
                    'codigo' => $movement->code,
                    'tipo' => $movement->type?->value ?? $movement->getRawOriginal('type'),
                    'estado' => $movement->status?->value ?? $movement->getRawOriginal('status'),
                    'usuario' => $movement->createdBy?->name,
                    'total_usd' => $movement->total_with_tax_usd,
                    'total_cup' => $movement->total_with_tax_cup,
                    'fecha' => $movement->created_at?->toDateTimeString(),
                ])
                ->all();

            return $this->export('historial_movimientos', ['codigo', 'tipo', 'estado', 'usuario', 'total_usd', 'total_cup', 'fecha'], $rows, $request->string('format')->toString());
        }

        return $controller->index($request);
    }

    /**
     * Products whose stock is zero in the given warehouse (or in total when null).
     */
    private function lowStockProducts(?int $warehouseId)
    {
        return Product::query()
            ->with(['category', 'unit'])
            ->withSum(
                ['stocks as quantity' => fn ($q) => $warehouseId !== null ? $q->where('warehouse_id', $warehouseId) : $q],
                'quantity',
            )
            ->get()
            ->filter(fn (Product $product) => (int) ($product->quantity ?? 0) === 0)
            ->values();
    }

    private function topProducts(string $from, string $to, ?int $warehouseId = null)
    {
        return DB::table('movement_item')
            ->join('movement', 'movement.id', '=', 'movement_item.movement_id')
            ->join('product', 'product.id', '=', 'movement_item.product_id')
            ->where('movement.type', 'venta')
            ->where('movement.status', 'activo')
            ->when($warehouseId !== null, fn ($q) => $q->where('movement.warehouse_id', $warehouseId))
            ->whereDate('movement.created_at', '>=', $from)
            ->whereDate('movement.created_at', '<=', $to)
            ->selectRaw('product.id, product.code, product.name, SUM(movement_item.quantity) as quantity, SUM(movement_item.subtotal_with_tax_usd) as total_usd')
            ->groupBy('product.id', 'product.code', 'product.name')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get();
    }

    private function byUser(string $from, string $to, ?int $warehouseId = null)
    {
        return DB::table('movement')
            ->join('user', 'user.id', '=', 'movement.created_by_user_id')
            ->where('movement.type', 'venta')
            ->where('movement.status', 'activo')
            ->when($warehouseId !== null, fn ($q) => $q->where('movement.warehouse_id', $warehouseId))
            ->whereDate('movement.created_at', '>=', $from)
            ->whereDate('movement.created_at', '<=', $to)
            ->selectRaw('user.id as user_id, user.name, COUNT(*) as count, SUM(movement.total_with_tax_usd) as total_usd')
            ->groupBy('user.id', 'user.name')
            ->orderByDesc('total_usd')
            ->get();
    }

    private function byCategory(string $from, string $to, ?int $warehouseId = null)
    {
        return DB::table('movement_item')
            ->join('movement', 'movement.id', '=', 'movement_item.movement_id')
            ->join('product', 'product.id', '=', 'movement_item.product_id')
            ->join('category', 'category.id', '=', 'product.category_id')
            ->where('movement.type', 'venta')
            ->where('movement.status', 'activo')
            ->when($warehouseId !== null, fn ($q) => $q->where('movement.warehouse_id', $warehouseId))
            ->whereDate('movement.created_at', '>=', $from)
            ->whereDate('movement.created_at', '<=', $to)
            ->selectRaw('category.id as category_id, category.name, SUM(movement_item.quantity) as count, SUM(movement_item.subtotal_with_tax_usd) as total_usd')
            ->groupBy('category.id', 'category.name')
            ->orderByDesc('total_usd')
            ->get();
    }

    /**
     * Excel report: per product, the units that left (salidas + ventas) within a
     * selected period and the current stock, scoped to the active location(s).
     */
    public function productExits(Request $request)
    {
        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['annual', 'monthly', 'weekly', 'custom'])],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'required_if:period_type,monthly', 'integer', 'min:1', 'max:12'],
            'date' => ['nullable', 'required_if:period_type,weekly', 'date'],
            'from' => ['nullable', 'required_if:period_type,custom', 'date'],
            'to' => ['nullable', 'required_if:period_type,custom', 'date', 'after_or_equal:from'],
        ]);

        [$from, $to, $label] = $this->resolvePeriod($validated);
        $warehouseIds = $this->resolvedWarehouseIds($request);

        // Outflows counted: salida + venta movements (active), within the period and scope.
        $exits = DB::table('movement_item')
            ->join('movement', 'movement.id', '=', 'movement_item.movement_id')
            ->whereIn('movement.type', ['salida', 'venta'])
            ->where('movement.status', 'activo')
            ->when($warehouseIds !== [], fn ($q) => $q->whereIn('movement.warehouse_id', $warehouseIds))
            ->whereBetween('movement.created_at', [$from, $to])
            ->groupBy('movement_item.product_id')
            ->selectRaw('movement_item.product_id, SUM(movement_item.quantity) as qty')
            ->pluck('qty', 'movement_item.product_id');

        // Current stock per product in the active scope.
        $stock = DB::table('product_warehouse')
            ->when($warehouseIds !== [], fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->pluck('qty', 'product_id');

        $rows = Product::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Product $product) => [
                'Producto' => $product->code,
                'Nombre del producto' => $product->name,
                'Periodo' => $label,
                'Salidas' => (int) ($exits[$product->id] ?? 0),
                'Stock actual' => (int) ($stock[$product->id] ?? 0),
            ])
            ->all();

        $format = $request->string('format', 'xlsx')->lower()->toString();
        $headers = ['Producto', 'Nombre del producto', 'Periodo', 'Salidas', 'Stock actual'];
        $suffix = match ($validated['period_type']) {
            'annual' => 'anual',
            'monthly' => 'mensual',
            'weekly' => 'semanal',
            default => $from->format('d-m-Y').'_'.$to->format('d-m-Y'),
        };

        return $this->export('reporte_salidas_'.$suffix, $headers, $rows, $format);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(array $v): array
    {
        $tz = 'America/Bogota';

        return match ($v['period_type']) {
            'annual' => (function () use ($v, $tz) {
                $year = (int) ($v['year'] ?? now($tz)->year);
                $from = Carbon::create($year, 1, 1, 0, 0, 0, $tz);

                return [$from->copy()->startOfDay(), $from->copy()->endOfYear(), "Año {$year}"];
            })(),
            'monthly' => (function () use ($v, $tz) {
                $year = (int) ($v['year'] ?? now($tz)->year);
                $month = (int) $v['month'];
                $from = Carbon::create($year, $month, 1, 0, 0, 0, $tz);

                return [$from->copy()->startOfMonth(), $from->copy()->endOfMonth(), sprintf('%04d-%02d', $year, $month)];
            })(),
            'weekly' => (function () use ($v, $tz) {
                $day = Carbon::parse($v['date'], $tz);
                $from = $day->copy()->startOfWeek();
                $to = $day->copy()->endOfWeek();

                return [$from, $to, "Semana {$from->toDateString()} a {$to->toDateString()}"];
            })(),
            default => (function () use ($v, $tz) {
                $from = Carbon::parse($v['from'], $tz)->startOfDay();
                $to = Carbon::parse($v['to'], $tz)->endOfDay();

                return [$from, $to, "{$from->toDateString()} a {$to->toDateString()}"];
            })(),
        };
    }

    private function export(string $name, array $headers, array $rows, string $format)
    {
        $normalizedRows = array_map(fn ($row) => array_map('strval', is_array($row) ? $row : (array) $row), $rows);

        return match ($format) {
            'xlsx' => $this->xlsx($name, $headers, $normalizedRows),
            'pdf' => $this->pdf($name, $headers, $normalizedRows),
            default => $this->csv($name, $headers, $normalizedRows),
        };
    }

    private function csv(string $name, array $headers, array $rows)
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $row[$header] ?? '', $headers));
        }
        rewind($handle);

        return response((string) stream_get_contents($handle), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
        ]);
    }

    private function xlsx(string $name, array $headers, array $rows)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $sheetRows = '';
        foreach (array_merge([$headers], array_map(fn ($row) => array_map(fn ($header) => $row[$header] ?? '', $headers), $rows)) as $rIndex => $row) {
            $sheetRows .= '<row r="'.($rIndex + 1).'">';
            foreach (array_values($row) as $cIndex => $value) {
                $ref = chr(65 + $cIndex).($rIndex + 1);
                $sheetRows .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }
            $sheetRows .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        return response()->download($tmp, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function pdf(string $name, array $headers, array $rows)
    {
        $lines = [$name, implode(' | ', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(fn ($header) => $row[$header] ?? '', $headers));
        }
        $text = implode("\n", array_slice($lines, 0, 45));
        $stream = "BT /F1 10 Tf 50 780 Td ".implode(' T* ', array_map(fn ($line) => '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).')', explode("\n", $text)))." ET";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length '.strlen($stream).' >> stream '.$stream.' endstream endobj',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }
        $pdf .= "trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
        ]);
    }
}

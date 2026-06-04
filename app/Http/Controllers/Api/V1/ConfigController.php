<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function todayRate(): JsonResponse
    {
        $today = now('America/Bogota')->toDateString();
        $rate = ExchangeRate::query()->where('rate_date', $today)->first();

        if ($rate) {
            return response()->json(['exists' => true, 'rate' => $rate]);
        }

        $latest = ExchangeRate::query()->orderByDesc('rate_date')->first();

        return response()->json(['exists' => false, 'rate' => $latest]);
    }

    public function saveRate(Request $request): JsonResponse
    {
        $data = $request->validate(['usd_to_cup' => ['required', 'numeric', 'gt:0', 'max:999999']]);
        $today = now('America/Bogota')->toDateString();
        $rate = ExchangeRate::query()->updateOrCreate(
            ['rate_date' => $today],
            ['usd_to_cup' => $data['usd_to_cup'], 'created_by_user_id' => $request->user()->id],
        );

        return response()->json(['data' => $rate]);
    }

    public function rates(Request $request): JsonResponse
    {
        $query = ExchangeRate::query()
            ->when($request->filled('from'), fn ($q) => $q->whereDate('rate_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('rate_date', '<=', $request->date('to')))
            ->orderByDesc('rate_date');

        return response()->json(['data' => $query->get()]);
    }

    public function taxRate(): JsonResponse
    {
        return response()->json(['value' => Setting::get('tax_rate', '0.00')]);
    }

    public function updateTaxRate(Request $request): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'numeric', 'min:0', 'max:100']]);

        return response()->json(['data' => Setting::put('tax_rate', number_format((float) $data['value'], 2, '.', ''), $request->user()->id)]);
    }

    public function business(): JsonResponse
    {
        return response()->json([
            'data' => [
                'business_name' => Setting::get('business_name', ''),
                'business_address' => Setting::get('business_address', ''),
                'business_phone' => Setting::get('business_phone', ''),
            ],
        ]);
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:60'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, (string) ($value ?? ''), $request->user()->id);
        }

        return $this->business();
    }
}

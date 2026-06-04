<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        return $this->list($request, Category::class, ['id', 'name', 'description', 'status']);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('category', 'name')],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => Category::query()->create($data)], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('category', 'name')->ignore($category->id)],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $category->fill($data)->save();

        return response()->json(['data' => $category->refresh()]);
    }

    public function deleteCategory(Category $category): JsonResponse
    {
        $category->softDeleteStatus();

        return response()->json(null, 204);
    }

    public function units(Request $request): JsonResponse
    {
        return $this->list($request, Unit::class, ['id', 'name', 'abbreviation', 'status']);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abbreviation' => ['required', 'string', 'max:10', Rule::unique('unit', 'abbreviation')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => Unit::query()->create($data)], 201);
    }

    public function updateUnit(Request $request, Unit $unit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'abbreviation' => ['sometimes', 'string', 'max:10', Rule::unique('unit', 'abbreviation')->ignore($unit->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $unit->fill($data)->save();

        return response()->json(['data' => $unit->refresh()]);
    }

    public function deleteUnit(Unit $unit): JsonResponse
    {
        $unit->softDeleteStatus();

        return response()->json(null, 204);
    }

    public function suppliers(Request $request): JsonResponse
    {
        return $this->list($request, Supplier::class, ['id', 'name', 'contact_name', 'phone', 'email', 'address', 'notes', 'status']);
    }

    public function showSupplier(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => Supplier::query()->create($data)], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $supplier->fill($data)->save();

        return response()->json(['data' => $supplier->refresh()]);
    }

    public function deleteSupplier(Supplier $supplier): JsonResponse
    {
        $supplier->softDeleteStatus();

        return response()->json(null, 204);
    }

    public function attributes(Request $request): JsonResponse
    {
        $attributes = Attribute::query()
            ->with('values')
            ->when($request->string('search')->toString() !== '', fn ($q) => $q->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $attributes]);
    }

    public function storeAttribute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('attribute', 'name')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => Attribute::query()->create($data)], 201);
    }

    public function updateAttribute(Request $request, Attribute $attribute): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60', Rule::unique('attribute', 'name')->ignore($attribute->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $attribute->fill($data)->save();

        return response()->json(['data' => $attribute->refresh()->load('values')]);
    }

    public function deleteAttribute(Attribute $attribute): JsonResponse
    {
        $attribute->softDeleteStatus();

        return response()->json(null, 204);
    }

    public function attributeValues(Attribute $attribute): JsonResponse
    {
        return response()->json(['data' => $attribute->values()->orderBy('value')->get()]);
    }

    public function storeAttributeValue(Request $request, Attribute $attribute): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:60', Rule::unique('attribute_value', 'value')->where('attribute_id', $attribute->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => $attribute->values()->create($data)], 201);
    }

    public function deleteAttributeValue(Attribute $attribute, AttributeValue $value): JsonResponse
    {
        abort_unless((int) $value->attribute_id === (int) $attribute->id, 404);
        $value->softDeleteStatus();

        return response()->json(null, 204);
    }

    /**
     * @param class-string<Model> $model
     * @param array<int, string> $columns
     */
    private function list(Request $request, string $model, array $columns): JsonResponse
    {
        $search = $request->string('search')->toString();
        $query = $model::query()
            ->select($columns)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name');

        if ($request->string('status')->toString() === EntityStatus::DELETED->value) {
            $query->withDeleted()->where('status', EntityStatus::DELETED->value);
        }

        return response()->json(['data' => $query->get()]);
    }
}

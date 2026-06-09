<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ListUsersRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = User::query()
            ->when(isset($filters['status']), fn (Builder $query) => $query->withDeleted()->where('status', $filters['status']))
            ->when(isset($filters['search']), function (Builder $query) use ($filters) {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => $this->serializeUser($user))->values(),
            'meta' => [
                'total' => $users->total(),
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= EntityStatus::ACTIVE->value;
        $warehouseIds = $data['warehouse_ids'] ?? [];
        unset($data['warehouse_ids']);

        $user = User::query()->create($data);
        $this->syncWarehouses($user, $warehouseIds);

        return response()->json(['data' => $this->serializeUser($user)], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->serializeUser($user)]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $warehouseIds = $data['warehouse_ids'] ?? null;
        unset($data['warehouse_ids']);

        $user->fill($data)->save();

        if ($user->isAdmin()) {
            $user->warehouses()->sync([]);
        } elseif ($warehouseIds !== null) {
            $user->warehouses()->sync($warehouseIds);
        }

        return response()->json(['data' => $this->serializeUser($user->refresh())]);
    }

    /**
     * Almaceneros keep explicit warehouse access; admins reach every warehouse
     * implicitly, so we never store rows for them.
     *
     * @param  array<int, int>  $warehouseIds
     */
    private function syncWarehouses(User $user, array $warehouseIds): void
    {
        $user->warehouses()->sync($user->isAdmin() ? [] : $warehouseIds);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $user->forceFill(['status' => $request->validated('status')])->save();

        return response()->json(['data' => $this->serializeUser($user->refresh())]);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, status: string}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'warehouses' => Warehouse::query()
                ->whereIn('id', $user->accessibleWarehouseIds())
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }
}

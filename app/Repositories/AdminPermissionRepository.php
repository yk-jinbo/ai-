<?php

namespace App\Repositories;

use App\Models\AdminPermissionModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdminPermissionRepository
{
    public function __construct(
        private AdminPermissionModel $model,
    ) {}

    /**
     * 根据编码查找权限。
     */
    public function findByCode(string $code): ?AdminPermissionModel
    {
        $query = $this->model->newQuery();
        $query->where('code', $code);

        return $query->first();
    }

    public function findById(int $id, bool $withRelations = false): ?AdminPermissionModel
    {
        $query = $this->model->newQuery();

        if ($withRelations) {
            $query->with(['menu', 'button']);
        }

        return $query->find($id);
    }

    public function generic(): Collection
    {
        $query = $this->model->newQuery();
        $query->whereNull('menu_id');
        $query->whereNull('button_id');
        $query->orderBy('id');

        return $query->get();
    }

    public function resource(): Collection
    {
        $query = $this->model->newQuery();
        $query->where(function (Builder $query): void {
            $query->whereNotNull('menu_id');
            $query->orWhereNotNull('button_id');
        });
        $query->with(['menu', 'button']);
        $query->orderBy('id');

        return $query->get();
    }

    public function findResourceByIdsForUpdate(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new Collection;
        }

        $query = $this->resourceQuery();
        $query->whereKey($ids);
        $query->lockForUpdate();
        $query->orderBy('id');

        return $query->get();
    }

    public function findByResourceIdsForUpdate(array $menuIds, array $buttonIds): Collection
    {
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        $buttonIds = array_values(array_unique(array_map('intval', $buttonIds)));
        if ($menuIds === [] && $buttonIds === []) {
            return new Collection;
        }

        $query = $this->resourceQuery();
        $query->where(function (Builder $query) use ($menuIds, $buttonIds): void {
            if ($menuIds !== []) {
                $query->whereIn('menu_id', $menuIds);
            }

            if ($buttonIds !== []) {
                if ($menuIds === []) {
                    $query->whereIn('button_id', $buttonIds);
                } else {
                    $query->orWhereIn('button_id', $buttonIds);
                }
            }
        });
        $query->lockForUpdate();
        $query->orderBy('id');

        return $query->get();
    }

    public function findByMenuId(int $menuId): ?AdminPermissionModel
    {
        $query = $this->model->newQuery();
        $query->where('menu_id', $menuId);

        return $query->first();
    }

    public function findByButtonId(int $buttonId): ?AdminPermissionModel
    {
        $query = $this->model->newQuery();
        $query->where('button_id', $buttonId);

        return $query->first();
    }

    public function createResource(string $code, string $name, ?int $menuId = null, ?int $buttonId = null): AdminPermissionModel
    {
        $query = $this->model->newQuery();

        return $query->create([
            'code' => $code,
            'name' => mb_substr($name, 0, 50),
            'menu_id' => $menuId,
            'button_id' => $buttonId,
        ]);
    }

    public function updateResource(AdminPermissionModel $permission, string $code, string $name): AdminPermissionModel
    {
        $permission->update(['code' => $code, 'name' => mb_substr($name, 0, 50)]);

        return $permission->fresh();
    }

    private function resourceQuery(): Builder
    {
        $query = $this->model->newQuery();
        $query->where(function (Builder $query): void {
            $query->whereNotNull('menu_id');
            $query->orWhereNotNull('button_id');
        });
        $query->with([
            'menu.parent.parent',
            'button.menu.parent.parent',
        ]);

        return $query;
    }
}

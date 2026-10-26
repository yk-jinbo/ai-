<?php

namespace App\Repositories;

use App\Models\AdminRoleModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminRoleRepository
{
    public function __construct(
        private AdminRoleModel $model,
    ) {}

    /**
     * 根据编码查找角色。
     */
    public function findByCode(string $code): ?AdminRoleModel
    {
        $query = $this->model->newQuery();
        $query->where('code', $code);

        return $query->first();
    }

    /**
     * 获取或创建角色。
     */
    public function firstOrCreate(array $attributes, array $values = []): AdminRoleModel
    {
        $query = $this->model->newQuery();

        return $query->firstOrCreate($attributes, $values);
    }

    public function findLiveById(int $id): ?AdminRoleModel
    {
        $query = $this->model->newQuery();
        $query->enabled();

        return $query->find($id);
    }

    public function findById(int $id, bool $withTrashed = false): ?AdminRoleModel
    {
        $query = $this->model->newQuery();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    public function findByIdForUpdate(int $id, bool $withTrashed = false): ?AdminRoleModel
    {
        $query = $this->model->newQuery();
        if ($withTrashed) {
            $query->withTrashed();
        }

        $query->whereKey($id);
        $query->lockForUpdate();

        return $query->first();
    }

    public function findDetailedById(int $id, bool $withTrashed = false): ?AdminRoleModel
    {
        $query = $this->model->newQuery();
        if ($withTrashed) {
            $query->withTrashed();
        }

        $query = $this->withManagementCounts($query);

        return $query->find($id);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->model->newQuery();
        if ((bool) ($filters['include_deleted'] ?? false)) {
            $query->withTrashed();
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $prefix = $keyword.'%';
            $query->where(function (Builder $query) use ($prefix): void {
                $query->where('code', 'like', $prefix);
                $query->orWhere('name', 'like', $prefix);
                $query->orWhereHas('users', static function (Builder $users) use ($prefix): void {
                    $users->where('username', 'like', $prefix);
                });
            });
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $pageSize = min(max((int) ($filters['page_size'] ?? 20), 1), 100);

        $query = $this->withManagementCounts($query);
        $query->orderByDesc('created_at');
        $query->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function existsByCode(string $code, ?int $ignoreId = null): bool
    {
        $query = $this->model->newQuery();
        $query->withTrashed();
        $query->where('code', $code);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    public function create(array $data): AdminRoleModel
    {
        $query = $this->model->newQuery();
        $role = $query->create($data);

        return $role->fresh();
    }

    public function update(AdminRoleModel $role, array $data): bool
    {
        return $role->update($data);
    }

    public function delete(AdminRoleModel $role): bool
    {
        return (bool) $role->delete();
    }

    public function restore(AdminRoleModel $role): bool
    {
        return (bool) $role->restore();
    }

    public function hasLiveUsers(AdminRoleModel $role): bool
    {
        $query = $role->users();
        $query->whereNull('admin_users.deleted_at');

        return $query->exists();
    }

    public function resourcePermissions(AdminRoleModel $role): Collection
    {
        $query = $role->permissions();
        $query->where(function (Builder $query): void {
            $query->where(function (Builder $menus): void {
                $menus->whereNotNull('admin_permissions.menu_id');
                $menus->whereNull('admin_permissions.button_id');
                $menus->whereHas('menu', static function (Builder $menu): void {
                    $menu->live();
                });
            });
            $query->orWhere(function (Builder $buttons): void {
                $buttons->whereNull('admin_permissions.menu_id');
                $buttons->whereNotNull('admin_permissions.button_id');
                $buttons->whereHas('button', static function (Builder $button): void {
                    $button->live();
                });
            });
        });
        $query->with(['menu', 'button']);
        $query->orderBy('admin_permissions.id');

        return $query->get();
    }

    public function replaceResourcePermissions(AdminRoleModel $role, array $permissionIds): void
    {
        $query = $role->permissions();
        $query->where(function (Builder $query): void {
            $query->whereNotNull('admin_permissions.menu_id');
            $query->orWhereNotNull('admin_permissions.button_id');
        });

        $existingIds = $query->pluck('admin_permissions.id');
        $existingIds = $existingIds->map(static fn ($id): int => (int) $id);
        $existingIds = $existingIds->all();

        if ($existingIds !== []) {
            $permissions = $role->permissions();
            $permissions->detach($existingIds);
        }

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        if ($permissionIds !== []) {
            $permissions = $role->permissions();
            $permissions->attach($permissionIds);
        }
    }

    public function active(): Collection
    {
        $query = $this->model->newQuery();
        $query->enabled();
        $query->orderBy('id');

        return $query->get();
    }

    /**
     * 批量锁定并返回有效角色组，调用方可据数量判断是否存在停用或删除角色。
     */
    public function findEnabledByIdsForUpdate(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new Collection;
        }

        $query = $this->model->newQuery();
        $query->enabled();
        $query->whereKey($ids);
        $query->lockForUpdate();
        $query->orderBy('id');

        return $query->get();
    }

    private function withManagementCounts(Builder $query): Builder
    {
        $query->withCount([
            'permissions as permission_count' => static function (Builder $permissions): void {
                $permissions->where(function (Builder $resources): void {
                    $resources->where(function (Builder $menus): void {
                        $menus->whereNotNull('admin_permissions.menu_id');
                        $menus->whereNull('admin_permissions.button_id');
                        $menus->whereHas('menu', static function (Builder $menu): void {
                            $menu->live();
                        });
                    });
                    $resources->orWhere(function (Builder $buttons): void {
                        $buttons->whereNull('admin_permissions.menu_id');
                        $buttons->whereNotNull('admin_permissions.button_id');
                        $buttons->whereHas('button', static function (Builder $button): void {
                            $button->live();
                        });
                    });
                });
            },
            'users as account_count' => static function (Builder $users): void {
                $users->whereNull('admin_users.deleted_at');
            },
        ]);

        return $query;
    }
}

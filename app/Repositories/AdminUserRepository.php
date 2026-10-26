<?php

namespace App\Repositories;

use App\Enums\UserStatus;
use App\Models\AdminRoleModel;
use App\Models\AdminUserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class AdminUserRepository
{
    public function __construct(
        private AdminUserModel $model,
    ) {}

    /**
     * 判断指定用户名是否已存在。
     */
    public function existsByUsername(string $username, ?int $ignoreId = null): bool
    {
        return $this->existsByUniqueField('username', $username, $ignoreId);
    }

    /**
     * 判断手机号是否已存在（包含软删除账号，保持数据库唯一约束一致）。
     */
    public function existsByPhone(string $phone, ?int $ignoreId = null): bool
    {
        return $this->existsByUniqueField('phone', $phone, $ignoreId);
    }

    /**
     * 判断邮箱是否已存在（包含软删除账号）。
     */
    public function existsByEmail(string $email, ?int $ignoreId = null): bool
    {
        return $this->existsByUniqueField('email', $email, $ignoreId);
    }

    /**
     * 根据用户名查找用户
     */
    public function findByUsername(string $username): ?AdminUserModel
    {
        $query = $this->model->newQuery();
        $query->where('username', $username);

        return $query->first();
    }

    /**
     * 在登录事务中锁定账号行，确保同一账号的凭证轮换串行执行。
     */
    public function findByUsernameForUpdate(string $username): ?AdminUserModel
    {
        $query = $this->model->newQuery();
        $query->where('username', $username);
        $query->lockForUpdate();

        return $query->first();
    }

    /**
     * 根据 ID 查找用户
     */
    public function findById(int $id): ?AdminUserModel
    {
        $query = $this->model->newQuery();

        return $query->find($id);
    }

    /**
     * 在账号写事务中锁定账号行；恢复场景可包含软删除行。
     */
    public function findByIdForUpdate(int $id, bool $withTrashed = false): ?AdminUserModel
    {
        $query = $this->model->newQuery();
        if ($withTrashed) {
            $query->withTrashed();
        }

        $query->whereKey($id);
        $query->lockForUpdate();

        return $query->first();
    }

    /**
     * 获取账号分页，角色组仅随当前页加载，避免把权限闭包带入账号主查询。
     */
    public function paginateManagement(array $filters): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $page = max((int) ($filters['page'] ?? 1), 1);
        $pageSize = min(max((int) ($filters['page_size'] ?? 20), 1), 100);
        $query = $this->model->newQuery();

        if ((bool) ($filters['include_deleted'] ?? false)) {
            $query->withTrashed();
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($keyword !== '') {
            $prefix = $keyword.'%';
            $query->where(function (Builder $search) use ($prefix): void {
                $search->where('username', 'like', $prefix);
                $search->orWhere('phone', 'like', $prefix);
                $search->orWhere('real_name', 'like', $prefix);
                $search->orWhereHas('roles', static function (Builder $roles) use ($prefix): void {
                    $roles->withTrashed();
                    $roles->where(function (Builder $roleSearch) use ($prefix): void {
                        $roleSearch->where('code', 'like', $prefix);
                        $roleSearch->orWhere('name', 'like', $prefix);
                    });
                });
            });
        }

        $query->with($this->managementRoleRelations());
        $query->orderByDesc('created_at');
        $query->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * 获取账号详情及角色组管理投影。
     */
    public function findDetailedById(int $id, bool $withTrashed = false): ?AdminUserModel
    {
        $user = $this->findById($id);
        if ($withTrashed) {
            $query = $this->model->newQuery();
            $query->withTrashed();
            $user = $query->find($id);
        }

        if ($user === null) {
            return null;
        }

        return $this->loadManagementRoles($user);
    }

    /**
     * 验证用户密码
     */
    public function verifyPassword(AdminUserModel $user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    /**
     * 更新最后登录时间
     */
    public function updateLastLoginAt(AdminUserModel $user): bool
    {
        return $user->update(['last_login_at' => now()]);
    }

    /**
     * 更新账号资料。
     */
    public function update(AdminUserModel $user, array $data): bool
    {
        return $user->update($data);
    }

    /**
     * 软删除账号。
     */
    public function delete(AdminUserModel $user): bool
    {
        return (bool) $user->delete();
    }

    /**
     * 恢复账号，不触碰既有凭证。
     */
    public function restore(AdminUserModel $user): bool
    {
        return (bool) $user->restore();
    }

    /**
     * 以完整集合语义替换账号角色组关系。
     */
    public function syncRoles(AdminUserModel $user, array $roleIds): void
    {
        $roles = $user->roles();
        $roles->sync($roleIds);
    }

    /**
     * 创建管理账号。模型的 hashed cast 负责最终持久化哈希。
     */
    public function createManagement(array $data): AdminUserModel
    {
        $query = $this->model->newQuery();
        $user = $query->create($data);

        return $user->fresh();
    }

    /**
     * 更新密码，只把哈希写入数据库。
     */
    public function updatePassword(AdminUserModel $user, string $password): bool
    {
        return $user->update(['password' => Hash::make($password)]);
    }

    /**
     * 加载接口序列化所需的角色与权限。
     */
    public function loadAuthorizationContext(AdminUserModel $user): AdminUserModel
    {
        if (! $this->hasEffectiveAuthorizationAccount($user)) {
            $user->setRelation('roles', new Collection);

            return $user;
        }

        return $user->load([
            'roles' => function ($roles): void {
                $roles->enabled();
                $roles->with([
                    'permissions' => function ($permissions): void {
                        $permissions->effective();
                    },
                ]);
            },
        ]);
    }

    /**
     * 判断账号是否拥有超级管理员角色。
     */
    public function isSuperAdmin(AdminUserModel $user): bool
    {
        if (! $this->hasEffectiveAuthorizationAccount($user)) {
            return false;
        }

        return $this->hasSuperAdminRole($user);
    }

    private function hasSuperAdminRole(AdminUserModel $user): bool
    {
        $query = $user->roles();
        $query->where('is_super_admin', true);
        $query->where('admin_roles.status', 'active');
        $query->whereNull('admin_roles.deleted_at');

        return $query->exists();
    }

    /**
     * 判断账号是否拥有指定权限编码。
     */
    public function hasPermission(AdminUserModel $user, string $permissionCode): bool
    {
        if (! $this->hasEffectiveAuthorizationAccount($user)) {
            return false;
        }

        if ($this->hasSuperAdminRole($user)) {
            return true;
        }

        $query = $user->roles();
        $query->where('admin_roles.status', 'active');
        $query->whereNull('admin_roles.deleted_at');
        $query->whereHas('permissions', function ($query) use ($permissionCode): void {
            $query->where('code', $permissionCode);
            $query->effective();
        });

        return $query->exists();
    }

    /**
     * 始终按数据库当前状态判断账号，避免陈旧模型继续获得授权。
     */
    private function hasEffectiveAuthorizationAccount(AdminUserModel $user): bool
    {
        $userId = $user->getKey();

        if (! is_int($userId) && ! ctype_digit((string) $userId)) {
            return false;
        }

        $query = $this->model->newQuery();
        $query->whereKey($userId);
        $query->where('status', UserStatus::ACTIVE->value);
        $query->whereNull('deleted_at');

        return $query->exists();
    }

    /**
     * 创建用户（用于 Seeder）
     */
    public function create(array $data): AdminUserModel
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $query = $this->model->newQuery();

        return $query->create($data);
    }

    /**
     * 为用户绑定角色（用于 Seeder）。
     */
    public function attachRole(AdminUserModel $user, AdminRoleModel $role): void
    {
        $roles = $user->roles();
        $roles->attach($role->getKey());
    }

    /**
     * 加载账号管理所需的角色组字段，不加载权限闭包。
     */
    private function loadManagementRoles(AdminUserModel $user): AdminUserModel
    {
        return $user->load($this->managementRoleRelations());
    }

    private function managementRoleRelations(): array
    {
        return [
            'roles' => function ($roles): void {
                $roles->withTrashed();
                $roles->withCount([
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
                $roles->orderBy('admin_roles.id');
            },
        ];
    }

    private function existsByUniqueField(string $field, string $value, ?int $ignoreId = null): bool
    {
        $query = $this->model->newQuery();
        $query->withTrashed();
        $query->where($field, $value);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}

# Repository 层说明

## 位置与命名

`app/Repositories/{实体}Repository.php`

Repository 与业务表、Model 一一对应。文件名和类名使用业务实体名，不携带物理表前缀。

```text
AdminUserModel       <-> AdminUserRepository
AdminRoleModel       <-> AdminRoleRepository
AdminPermissionModel <-> AdminPermissionRepository
```

## 双数据库 Repository

Repository 不接收连接名参数，也不在方法内切换默认连接。连接由注入 Model 的 `$connection` 固定决定：

```php
class BridgeRecordRepository
{
    public function __construct(
        private BridgeRecordModel $model,
    ) {}

    public function create(array $data): BridgeRecordModel
    {
        $query = $this->model->newQuery();

        return $query->create($data);
    }
}
```

上例中的 `BridgeRecordModel` 声明了 `data_bridge` 连接，因此 Repository 的查询和写入都会落到 B 库。主库和 B 库的表不能由同一个 Repository 注入多个 Model；跨库流程由 Service 注入两个独立 Repository。

## 职责

Repository 负责对应表的全部数据库访问：

- 查找、创建、更新、删除；
- 条件组合、排序、分页和统计；
- 调用 Model 已定义的作用域；
- 调用 Model 已定义的关联关系；
- 返回 Model、Collection 或分页对象。

Repository 不处理 HTTP、业务响应、事务编排或 Redis key。

## 强制约束

1. 构造函数只注入对应 Model。
2. 一个 Repository 不得查询多个无关联所有权的实体 Model。
3. 禁止创建 `AuthRepository`、`CheckoutRepository` 等接口或流程仓储。
4. Service/Controller 禁止绕过 Repository 查询数据库。
5. 关联结构在 Model 定义，Repository 负责调用。

## 标准写法

```php
class AdminUserRepository
{
    public function __construct(
        private AdminUserModel $model,
    ) {}

    public function findById(int $id): ?AdminUserModel
    {
        return $this->model->newQuery()->find($id);
    }

    public function create(array $data): AdminUserModel
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(AdminUserModel $user, array $data): AdminUserModel
    {
        $user->update($data);

        return $user->refresh();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->active()
            ->latest()
            ->paginate(min(max($perPage, 1), 100));
    }

    public function loadRoles(AdminUserModel $user): AdminUserModel
    {
        return $user->loadMissing(['roles.permissions']);
    }
}
```

## 关联写入

关系定义在 Model 后，由拥有该关系的 Repository 调用：

```php
public function attachRole(AdminUserModel $user, AdminRoleModel $role): void
{
    $user->roles()->syncWithoutDetaching([$role->getKey()]);
}
```

多个独立实体的业务顺序由 Service 编排：

```php
class UserService
{
    public function __construct(
        private AdminUserRepository $userRepository,
        private AdminRoleRepository $roleRepository,
    ) {}
}
```

## 何时创建关联表对应层

- 仅承载外键关系：通过 Model 关联访问，不创建空壳层。
- 具备独立业务字段和独立 CRUD：创建对应 Model/Repository。

## 禁止事项

- 一个 Repository 注入 `AdminUserModel` 和 `AdminRoleModel`；
- 一个 Repository 同时注入主库 Model 和 `data_bridge` 库 Model；
- 通过请求参数传入连接名，或使用 `DB::connection($request->input('connection'))`；
- Repository 之间编排业务流程；
- 返回裸数组替代 Model/Collection；
- 在 Repository 中调用 `ResponseHelper`；
- 在 Repository 中写 Redis 或缓存业务规则。

## 自查

- 与数据表、Model 一一对应；
- 只注入对应 Model；
- CRUD 和关联查询均在此层；
- 排序字段和分页大小有白名单/边界；
- Service/Controller 无直接 Model 查询。

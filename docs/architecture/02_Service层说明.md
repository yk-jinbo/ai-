# Service 层说明

## 位置

`app/Services/{实体或流程}Service.php`

## 职责

Service 是业务中心，负责：

- 业务规则校验与异常抛出；
- 写操作事务；
- 调用一个或多个 Repository；
- 跨实体、跨模块流程编排；
- 按接口契约组织返回数据。

Service 禁止直接使用 `Model::`、`new Model`、Eloquent Query Builder 或 `DB::table()` 查询。数据库访问必须进入对应 Repository。

## 标准写法

```php
class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository,
    ) {}

    public function create(array $data): OrderModel
    {
        DB::beginTransaction();

        try {
            $products = $this->productRepository->findByIds($data['product_ids']);
            ResponseHelper::errorIf($products->count() !== count($data['product_ids']), '商品不存在');

            $order = $this->orderRepository->create($data);
            DB::commit();

            return $order;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
```

## 查询方法

Service 查询方法返回 Model、Collection 或分页对象，不返回用于表达失败的 `null` 或 `false`。数据不存在时抛业务异常。

```php
public function getById(int $id): AdminUserModel
{
    $user = $this->userRepository->findById($id);
    ResponseHelper::errorIf(! $user, '用户不存在');

    return $user;
}
```

## 跨表流程

关联关系定义在 Model，对应 Repository 调用关系。需要协调多个独立实体写操作时，由 Service 注入多个 Repository，不创建聚合 Repository。

```php
public function assignRole(int $userId, int $roleId): void
{
    $user = $this->userRepository->findById($userId);
    $role = $this->roleRepository->findById($roleId);

    ResponseHelper::errorIf(! $user || ! $role, '用户或角色不存在');
    $this->userRepository->attachRole($user, $role);
}
```

## 禁止事项

- 返回 `JsonResponse` 或调用 `ResponseHelper::success()`；
- 接收 HTTP Request 对象；
- 直接操作 Model 或 Query Builder；
- 在 Service 内定义 Eloquent 关联；
- 用 `AuthRepository` 等流程仓储绕过一表一 Repository。

## 自查

- 所有数据库操作均通过 Repository；
- 写操作包含事务和异常透传；
- 业务错误通过 `ResponseHelper::errorIf()` 或 `throwError()` 抛出；
- 返回类型明确；
- 跨实体逻辑由 Service 编排。

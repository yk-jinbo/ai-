# 控制器层（Controller）说明

## 位置

业务 Controller 必须放在属性路由扫描目录中：

```text
app/Http/Controllers/Api/
├── User/UserController.php
├── Product/ProductController.php
└── Order/OrderController.php
```

每个资源 Controller 对应一个同实体 Request：

```text
UserController.php    ↔ UserRequest.php
ProductController.php ↔ ProductRequest.php
OrderController.php   ↔ OrderRequest.php
```

## 职责

Controller 是 HTTP 入口，只负责：

- 接收已由 FormRequest 校验的参数。
- 调用 Service。
- 通过 `ResponseHelper` 返回 `JsonResponse`。

Controller 不负责业务规则、事务、数据库查询或异常转换。业务异常由 Service 抛出，全局异常渲染统一处理。

## Controller 与 Request 的对应关系

同一个 Controller 的所有路由动作都注入同一个 Request。Request 的 `rules()` 根据当前 Controller 方法，分发到对应的 `rulesFor{Action}()`：

| Controller 动作 | Request 规则方法 |
| --- | --- |
| `index()` | `rulesForIndex()` |
| `search()` | `rulesForSearch()` |
| `show()` | `rulesForShow()` |
| `store()` | `rulesForStore()` |
| `update()` | `rulesForUpdate()` |
| `destroy()` | `rulesForDestroy()` |

规则方法使用 `rulesFor{Action}()` 命名，不直接使用 `user()`、`route()`、`input()` 等名称，避免覆盖 Laravel Request 已有方法。

## 标准写法

```php
<?php

namespace App\Http\Controllers\Api\Product;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;

#[Prefix('api/products')]
class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
    ) {}

    #[Get('/', name: 'api.products.index')]
    public function index(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ResponseHelper::success(
            $this->productService->getPaginated((int) ($data['per_page'] ?? 15)),
        );
    }

    #[Get('/{id}', name: 'api.products.show')]
    public function show(ProductRequest $request, int $id): JsonResponse
    {
        return ResponseHelper::success($this->productService->getById($id));
    }

    #[Post('/', name: 'api.products.store')]
    public function store(ProductRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->productService->create($request->validated()),
            '商品创建成功',
            201,
        );
    }

    #[Put('/{id}', name: 'api.products.update')]
    public function update(ProductRequest $request, int $id): JsonResponse
    {
        return ResponseHelper::success(
            $this->productService->update($id, $request->validated()),
            '商品更新成功',
        );
    }

    #[Delete('/{id}', name: 'api.products.destroy')]
    public function destroy(ProductRequest $request, int $id): JsonResponse
    {
        $this->productService->delete($id);

        return ResponseHelper::success(null, '商品删除成功');
    }
}
```

无额外请求参数的 `show()`、`destroy()` 仍注入同一个 Request，对应规则方法显式返回空数组。这样新增动作时不会绕过统一校验约定。

## 禁止写法

```php
// 禁止：Controller 内直接校验。
$request->validate(['name' => 'required']);

// 禁止：Controller 直接查询 Model。
$product = $this->productService->getById($id);

// 禁止：自行拼响应或二次包装 ResponseHelper。
return response()->json(ResponseHelper::success($product));

// 禁止：捕获业务异常后把数据不存在改成 404。
try {
    // ...
} catch (Throwable $e) {
    return ResponseHelper::error($e->getMessage(), httpStatus: 404);
}
```

## 自查清单

- Controller 位于 `app/Http/Controllers/Api/`。
- Controller 与 Request 按实体一一对应。
- 每个路由动作都注入同一个 Request。
- Controller 方法保持简短，只调用 Service 和 `ResponseHelper`。
- Controller 不直接验证、不访问 Model、不管理事务、不写 try-catch。
- 路由名称遵循 `api.{模块}.{action}`。

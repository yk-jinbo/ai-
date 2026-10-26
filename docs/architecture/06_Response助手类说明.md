# 统一响应助手（ResponseHelper）说明

## 📍 位置
```
app/Helpers/ResponseHelper.php
```

## 🎯 作用

统一 API 响应格式，参考 ebanos 项目的辅助类（Support）设计，以**静态方法**调用。

## 📝 响应格式

### 标准响应结构
```json
{
    "code": 0,
    "message": "success",
    "data": { ... }
}
```

- `code`: 业务状态码（0 表示成功，非 0 表示失败）
- `message`: 提示信息
- `data`: 返回的数据

## 🔧 使用方法

### 1. 基础响应方法

```php
use App\Helpers\ResponseHelper;

// 标准响应（类似 ebanos Support::response）
return ResponseHelper::response($data, 'success', 0);

// 参数说明：
// response($data = null, $message = 'success', $code = 0)
```

### 2. 成功响应（快捷方法）

```php
// 基本用法
return ResponseHelper::success($data);

// 自定义消息
return ResponseHelper::success($data, '操作成功');
```

**响应示例：**
```json
{
    "code": 0,
    "message": "success",
    "data": {
        "id": 1,
        "name": "iPhone 15 Pro"
    }
}
```

### 3. 失败响应（快捷方法）

```php
// 基本用法
return ResponseHelper::error('操作失败', httpStatus: 400);

// 自定义错误码
return ResponseHelper::error('库存不足', -1001, httpStatus: 400);

// 带附加数据
return ResponseHelper::error('验证失败', -1, $errors, 422);
```

**响应示例：**
```json
{
    "code": -1,
    "message": "操作失败",
    "data": null
}
```

### 4. 条件判断抛出异常（类似 ebanos Support::errorIf）

```php
use App\Helpers\ResponseHelper;

// 如果条件为真，抛出异常
ResponseHelper::errorIf(
    $user->balance < 100,
    '余额不足',
    -1001
);

// 带回调函数
ResponseHelper::errorIf(
    $stock < $quantity,
    '库存不足',
    -1001,
    function() {
        // 记录日志或其他操作
        Log::warning('库存不足');
    }
);

// 直接抛出异常
ResponseHelper::throwError('操作失败', -1);
```

### 5. 获取异常详细信息

```php
try {
    // 业务逻辑
} catch (\Exception $e) {
    // 获取异常详细信息
    $errorInfo = ResponseHelper::logInfo($e);
    // ['file' => '...', 'line' => 123, 'message' => '...']
    
    Log::error('业务异常', $errorInfo);
}
```

## 📝 控制器层（Controller）中的使用

同一个资源 Controller 的全部动作注入同实体 Request，并直接返回 `ResponseHelper` 生成的 `JsonResponse`。Controller 不二次调用 `response()->json()`，也不捕获业务异常。

```php
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
        '创建成功',
        201,
    );
}

#[Put('/{id}', name: 'api.products.update')]
public function update(ProductRequest $request, int $id): JsonResponse
{
    return ResponseHelper::success(
        $this->productService->update($id, $request->validated()),
        '更新成功',
    );
}

#[Delete('/{id}', name: 'api.products.destroy')]
public function destroy(ProductRequest $request, int $id): JsonResponse
{
    $this->productService->delete($id);

    return ResponseHelper::success(null, '删除成功');
}
```

## 📝 服务层（Service）中的使用

```php
public function create(array $data): DemoProductModel
{
    DB::beginTransaction();
    try {
        // 使用 errorIf 验证业务规则
        ResponseHelper::errorIf(
            $this->productRepository->existsByName($data['name']),
            '商品名称已存在'
        );
        
        $product = $this->productRepository->create($data);
        
        DB::commit();
        return $product;
        
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
}
```

## 🎨 最佳实践

### 1. HTTP 状态码使用

```php
// 成功
return ResponseHelper::success($data, httpStatus: 200);            // 查询成功
return ResponseHelper::success($data, httpStatus: 201);            // 创建成功

// 客户端错误
return ResponseHelper::error('参数错误', httpStatus: 400);         // 请求错误
return ResponseHelper::error('未授权', httpStatus: 401);           // 未登录
return ResponseHelper::error('无权限', httpStatus: 403);           // 无权限
return ResponseHelper::error('不存在', httpStatus: 404);           // 资源不存在
return ResponseHelper::error('验证失败', httpStatus: 422);         // 验证失败

// 服务器错误
return ResponseHelper::error('服务器错误', httpStatus: 500);       // 服务器错误
```

### 2. 业务状态码规范

```php
// 建议在 app/Constants/ErrorCode.php 中定义
class ErrorCode
{
    const SUCCESS = 0;                  // 成功
    const ERROR = -1;                   // 通用错误
    
    const INVALID_PARAMS = -1001;       // 参数错误
    const UNAUTHORIZED = -1002;         // 未授权
    const FORBIDDEN = -1003;            // 无权限
    const NOT_FOUND = -1004;            // 资源不存在
    
    const INSUFFICIENT_STOCK = -2001;   // 库存不足
    const ORDER_CANCELLED = -2002;      // 订单已取消
    const PAYMENT_FAILED = -2003;       // 支付失败
}

// 使用
return ResponseHelper::error('库存不足', ErrorCode::INSUFFICIENT_STOCK, httpStatus: 400);
```

### 3. 业务规则验证

```php
// Service 层中使用 errorIf
public function cancel(int $orderId): OrderModel
{
    $order = $this->orderRepository->findById($orderId);
    ResponseHelper::errorIf(! $order, '订单不存在');
    
    // 验证订单状态
    ResponseHelper::errorIf(
        !$order->canCancel(),
        '当前状态的订单无法取消'
    );
    
    // 业务逻辑...
}
```

### 4. 带回调的异常处理

```php
ResponseHelper::errorIf(
    $stock < $quantity,
    '库存不足',
    -2001,
    function() use ($product, $quantity) {
        // 记录预警日志
        Log::warning('库存预警', [
            'product_id' => $product->id,
            'stock' => $product->stock,
            'required' => $quantity,
        ]);
    }
);
```

## 📚 方法列表

| 方法 | 说明 | 参数 |
|------|------|------|
| `response()` | 基础响应方法 | `$data, $message, $code` |
| `success()` | 成功响应 | `$data, $message` |
| `error()` | 失败响应 | `$message, $code, $data` |
| `errorIf()` | 条件判断抛异常 | `$condition, $message, $code, $callback` |
| `throwError()` | 直接抛异常 | `$message, $code` |
| `logInfo()` | 获取异常详情 | `$exception` |

## 🔄 与 ebanos 辅助类（Support）的对比

| 项目 | 类名 | 方法名 |
|------|------|--------|
| **ebanos** | `Support` | `response()`, `errorIf()`, `error()`, `logInfo()` |
| **当前项目** | `ResponseHelper` | `response()`, `success()`, `error()`, `errorIf()`, `throwError()`, `logInfo()` |

**改进点：**
- ✅ 添加了快捷方法 `success()`
- ✅ 添加了可直接抛出异常的方法 `throwError()`
- ✅ 保留了 ebanos 的核心方法
- ✅ 使用静态方法，调用更方便

## ⚠️ 注意事项

1. **统一格式**：整个项目使用统一的响应格式
2. **HTTP 状态码**：正确使用 HTTP 状态码
3. **业务状态码**：业务码在 `code` 字段中
4. **错误信息**：`message` 要清晰明确
5. **静态调用**：所有方法都是静态方法，直接调用

## 💡 使用建议

1. **控制器层（Controller）**：使用 `success()` 和 `error()` 返回响应
2. **服务层（Service）**：使用 `errorIf()` 验证业务规则
3. **异常日志**：使用 `logInfo()` 获取异常详情记录日志
4. **回调处理**：需要额外操作时使用 `errorIf()` 的回调参数

---

**参考 ebanos 设计，使用静态方法，更简洁高效！**

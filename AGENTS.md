# AGENTS.md — 项目开发规范（AI 协作手册）

> **本文件是本仓库唯一的「强制开发规范」。** 任何 AI 或开发者在本仓库新增、修改代码前，必须完整阅读本文档并严格遵守。本文档基于现有目录结构提炼而成，代码模板可直接复制套用；具体接口契约和实现事实以已确认需求、源码、测试与架构文档为准。

---

## 1. 项目概览

- **定位**：用户资产中心 · PC 管理系统，**纯 API 服务**（无服务端页面、无前端构建产物）。
- **框架**：Laravel 13（PHP ^8.3）。
- **数据层**：外部 MySQL 主库 + `data_bridge` 第二库 + Redis（`.env` 指向 `host.docker.internal`）。
- **部署**：Docker Compose（PHP-FPM + Nginx）；宿主机端口由 `.env` 的 `PC_MANAGEMENT_HTTP_PORT` 配置。
- **认证**：Laravel Sanctum Personal Access Token，有效期 7 天；使用自定义 `km_admin_personal_access_tokens` 表；同一账号只允许一份有效凭证。
- **路由**：属性路由（注解）`spatie/laravel-route-attributes`，只扫描 `app/Http/Controllers/Api/`。
- **响应**：统一 `ResponseHelper` → `{ code, message, data }`；全局异常渲染已配置于 `bootstrap/app.php`。
- **业务码**：`App\Enums\ApiCode`（int backed enum，`SUCCESS=0`、`ERROR=-1`、`INVALID_PARAMS=-1001`、`UNAUTHORIZED=-1002`、`FORBIDDEN=-1003`、`NOT_FOUND=-1004`、`CONFLICT=-1005`）。

### 当前实现状态（事实基线）

| 状态 | 内容 | 说明 |
| --- | --- | --- |
| ✅ 已实现 | `GET /api/health`；`ApiCode`、`UserStatus` 枚举；`ResponseHelper`；全局异常渲染（`bootstrap/app.php`）；`ApiCodeTest`、`ResponseHelperTest`、健康接口 Feature 测试 | 有代码或测试支撑 |
| ✅ 已实现 | 认证系统：`POST /api/auth/login`、`GET /api/auth/me`、`POST /api/auth/logout`；Laravel Sanctum + `km_admin_personal_access_tokens`；RBAC 权限中间件（`account.status`、`permission`）；`km_admin_users`、`km_admin_roles`、`km_admin_permissions`、`km_admin_user_roles`、`km_admin_role_permissions`、`km_admin_personal_access_tokens` 六张表；`KmAuthSeeder` | 完整认证和 RBAC 闭环，有测试 |
| ✅ 已实现 | `CacheManager`、`CacheScope`；`config/cache.php` redis_connection/namespace/version/hash_key；`CacheScopeTest` | Redis Factory 注入、HMAC key，直接使用 Redis 命令，未绑定业务缓存类 |
| ⚠️ Demo/示例 | `DemoProductController`、`DemoProductRequest`、`DemoProductService`、`DemoProductRepository`、`DemoProductModel` 模型；`docs/architecture/*` | Controller/Request 一一对应，Factory 和 Feature 测试已补齐；仍是学习材料，未验收，不得作为已交付能力描述 |
| ✅ 外部数据库 | 两套数据库表结构由开发者在数据库侧人工建立和维护；开发与镜像两套 Compose 均只启动 app/nginx，不创建 db/redis | 应用通过 `DB_*` 和 `DATA_BRIDGE_DB_*` 连接外部依赖 |

---

## 2. 核心架构（必须遵守）

```
Request(FormRequest)
   → Controller（薄：接收参数、调 Service、返回响应）
      → Service（业务中心：编排、事务、规则校验、抛异常）
         → Repository（必需：数据访问层，唯一查询入口）
            → Model（Eloquent：表映射、关联、作用域、访问器）
```

| 层次 | 职责 | 必需/可选 |
|------|------|-----------|
| Controller | 接收请求、参数绑定、调用 Service、返回 `JsonResponse` | 必需 |
| Service | 业务逻辑编排、事务管理、业务规则校验、抛异常 | 必需 |
| Model | 表映射、关联关系、查询作用域、访问器 | 必需 |
| Repository | 与 Model 一一对应，统一封装对应表的数据访问 | 必需 |
| CacheManager/CacheScope | Redis connection、命名空间与 key 的统一适配；不承载业务命令或规则 | 推荐 |
| Request | 参数校验、错误消息、字段别名 | 推荐 |
| Enum | 状态/类型常量，禁止魔法字符串 | 推荐 |
| ResponseHelper | 统一响应结构 | 必需 |

**核心原则（YAGNI）**：
1. Controller 必须是「薄薄的一层」——只做 HTTP 处理，**禁止写业务逻辑**，方法体尽量控制在 10 行内。
2. Service 是业务中心——所有业务逻辑（规则校验、事务、库存扣减等）都放这里。
3. Model 与 Repository 按业务数据表一一对应——物理表 `km_admin_users` 映射为 `AdminUserModel` 和 `AdminUserRepository`；类名、文件名和 `$table` 使用去掉物理前缀后的完整逻辑表名，`km_` 由 `config/database.php` 的连接前缀统一追加。Repository 只能注入对应 Model，禁止使用 `AuthRepository` 等流程名聚合多个表。
4. Repository 是必需层——**所有数据库查询（含简单 CRUD）必须收敛到对应 Repository**，Service 内禁止直接使用 `Model::` / Query Builder 查询；关联关系和查询作用域定义在 Model，Repository 调用这些定义完成关联查询。
5. Request 负责验证——每个资源的 Controller 对应一个同实体 Request，由 Request 按 Controller 动作分发规则；**永远不要在 Controller 里写 `$request->validate()` 或其他验证逻辑**。
6. 统一响应——所有 API 必须通过 `ResponseHelper` 返回 `code/message/data`，**禁止自行拼 JSON**（`UserController.php` 中的旧式手拼响应属于反例，勿模仿）；Controller 直接 `return ResponseHelper::xxx(...)`，**禁止再 `response()->json()` 二次包装**。

---

## 3. 目录结构与职责

```
pc-management-system/
├── app/
│   ├── Enums/                    # 枚举：状态、类型常量（ApiCode、UserStatus）
│   ├── Exceptions/               # 自定义异常（当前目录不存在，按需创建前先说明方案）
│   ├── Helpers/                  # 全局助手：ResponseHelper（统一响应）
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php    # 基类
│   │   │   ├── Api/              # ⚠️ 唯一会被注解路由扫描的目录
│   │   │   │   └── {模块}/       # 按业务模块分子目录（如 Product/）
│   │   │   └── UserController.php  # ⚠️ 遗留 Demo，位于扫描范围外，勿新增此类文件
│   │   ├── Requests/             # FormRequest：每个 Controller 对应一个同实体 Request
│   │   └── Middleware/           # 认证、账号状态和权限中间件
│   ├── Models/                   # 每张业务表一个 {实体}Model
│   ├── Repositories/             # 每个 Model 对应一个 {实体}Repository
│   ├── Services/                 # 业务逻辑层
│   ├── Caches/                   # 缓存适配层（CacheManager、CacheScope）
│   └── Providers/
├── bootstrap/app.php             # 应用配置 + 全局异常渲染（已配置，勿重复处理框架异常）
├── config/
│   ├── route-attributes.php      # ⚠️ 注解路由扫描配置：仅 app/Http/Controllers/Api/
│   └── ...
├── database/
│   ├── factories/                # 工厂（测试用）
│   └── seeders/                  # 数据填充
├── routes/
│   ├── api.php                   # ⚠️ 只写全局/兜底路由，业务路由一律用注解
│   └── console.php
├── tests/
│   ├── Feature/                  # HTTP / 数据库 / JSON 契约测试
│   └── Unit/                     # 纯逻辑测试
├── docs/                         # 文档（架构分层说明见 docs/architecture/）
└── docker/                       # Nginx 等部署配置
```

> 说明：`app/Exceptions` 目录当前不存在，新增前先说明方案、影响和测试；`bootstrap/app.php` 已配置全局异常渲染（见 §6.3）。Models/Repositories 使用业务实体名，不复制物理表前缀；Model 必须显式声明与数据库表一致的 `$table`。

---

## 4. 分层开发规范（含代码模板）

### 4.1 Controller

- **位置**：`app/Http/Controllers/Api/{模块}/{模块}Controller.php`（模块名 `PascalCase`）。
- **必须**：继承 `App\Http\Controllers\Controller`；构造函数注入依赖；方法返回 `Illuminate\Http\JsonResponse`；使用注解路由；直接 `return ResponseHelper::xxx(...)`（禁止 `response()->json()` 二次包装）。
- **禁止**：在 Controller 中写业务逻辑、直接 new Service（用依赖注入）、自行拼响应数组、catch 后返回 404 表示数据不存在。
- 业务异常统一由全局异常处理兜底（见 §6.2），Controller 不写 try-catch。

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
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * 商品接口控制器
 * 负责接收商品相关 HTTP 请求，并把已校验参数交给商品服务处理
 */
#[Prefix('api/products')]
class ProductController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * 注入商品业务服务
     *
     * 业务逻辑：保存容器提供的商品服务，供各接口动作调用。
     *
     * @param  ProductService  $productService  商品业务服务
     * @return void
     */
    public function __construct(
        private ProductService $productService,
    ) {}

    /**
     * 获取商品列表
     *
     * 业务逻辑：读取已校验的分页参数，并返回商品分页结果。
     *
     * @param  ProductRequest  $request  商品列表请求
     * @return JsonResponse 统一结构的商品列表响应
     *
     * @throws \Throwable 商品查询失败时向上抛出
     */
    #[Get('/', name: 'api.products.index')]
    public function index(ProductRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $products = $this->productService->getPaginated((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE));

        return ResponseHelper::success($products);
    }

    /**
     * 获取商品详情
     *
     * 业务逻辑：把商品 ID 交给 Service 查询；数据不存在时由 Service 抛出业务异常。
     *
     * @param  ProductRequest  $request  商品详情请求
     * @param  int  $id  商品 ID
     * @return JsonResponse 统一结构的商品详情响应
     *
     * @throws \Throwable 商品不存在或查询失败时向上抛出
     */
    #[Get('/{id}', name: 'api.products.show')]
    public function show(ProductRequest $request, int $id): JsonResponse
    {
        $product = $this->productService->getById($id);

        return ResponseHelper::success($product);
    }

    /**
     * 创建商品
     *
     * 业务逻辑：把已校验的商品数据交给 Service 创建，并返回创建结果。
     *
     * @param  ProductRequest  $request  商品创建请求
     * @return JsonResponse 统一结构的商品创建响应
     *
     * @throws \Throwable 商品规则校验或写入失败时向上抛出
     */
    #[Post('/', name: 'api.products.store')]
    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        return ResponseHelper::success($product, '商品创建成功', HttpResponse::HTTP_CREATED);
    }

    /**
     * 更新商品
     *
     * 业务逻辑：把商品 ID 和已校验数据交给 Service 更新，并返回最新商品。
     *
     * @param  ProductRequest  $request  商品更新请求
     * @param  int  $id  商品 ID
     * @return JsonResponse 统一结构的商品更新响应
     *
     * @throws \Throwable 商品不存在、规则校验或写入失败时向上抛出
     */
    #[Put('/{id}', name: 'api.products.update')]
    public function update(ProductRequest $request, int $id): JsonResponse
    {
        $product = $this->productService->update($id, $request->validated());

        return ResponseHelper::success($product, '商品更新成功');
    }

    /**
     * 删除商品
     *
     * 业务逻辑：把商品 ID 交给 Service 删除，完成后返回统一成功响应。
     *
     * @param  ProductRequest  $request  商品删除请求
     * @param  int  $id  商品 ID
     * @return JsonResponse 统一结构的商品删除响应
     *
     * @throws \Throwable 商品不存在或删除失败时向上抛出
     */
    #[Delete('/{id}', name: 'api.products.destroy')]
    public function destroy(ProductRequest $request, int $id): JsonResponse
    {
        $this->productService->delete($id);

        return ResponseHelper::success(null, '商品删除成功');
    }
}
```

### 4.2 Request（FormRequest）

- **位置**：`app/Http/Requests/`，命名 `{实体}Request`（如 `ProductRequest`）；一个资源 Controller 只对应一个同实体 Request。
- Controller 的所有路由动作都注入同一个 Request；`rules()` 根据当前 Controller 方法分发到 `rulesFor{Action}()`，例如 `store` 对应 `rulesForStore()`。
- `rules()` 必须覆盖 Controller 的全部路由动作；未知动作必须抛 `LogicException`，禁止以空规则静默放行。无额外参数的动作也显式返回空数组。
- 动作规则方法使用 `rulesFor{Action}()`，禁止直接使用 `user()`、`route()`、`input()` 等可能与 Laravel Request 继承方法冲突的名称。
- **必须实现**：`authorize()`（权限检查）、`rules()`（动作分发）、`messages()`（中文错误消息）、`attributes()`（中文字段名）。
- 校验失败已由全局异常处理统一为 422（`ApiCode::INVALID_PARAMS`），**无需重写 `failedValidation`**。
- `unique` / `exists` 的表名必须引用对应 Model 的 `TABLE` 常量，并与数据库表一致；更新场景用 `sometimes` + `Rule::unique(...)->ignore($id)`。

```php
<?php

namespace App\Http\Requests;

use App\Models\ProductModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * 商品接口请求校验器
 * 负责按商品控制器动作分发校验规则，并提供中文校验文案
 */
class ProductRequest extends FormRequest
{
    private const MAX_PER_PAGE = 100;

    /**
     * 判断当前请求是否允许进入参数校验
     *
     * 业务逻辑：接口权限由路由中间件处理，因此允许请求继续校验。
     *
     * @return bool 始终返回 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 返回当前控制器动作的校验规则
     *
     * 业务逻辑：按动作名显式分发规则，未知动作失败关闭，避免未校验参数进入业务层。
     *
     * @return array<string, mixed> 当前动作的字段校验规则
     *
     * @throws LogicException 当前动作未配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'index' => $this->rulesForIndex(),
            'search' => $this->rulesForSearch(),
            'show' => $this->rulesForShow(),
            'store' => $this->rulesForStore(),
            'update' => $this->rulesForUpdate(),
            'destroy' => $this->rulesForDestroy(),
            default => throw new LogicException('ProductRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回商品字段的中文校验消息
     *
     * 业务逻辑：为明确的校验规则提供稳定、可读的用户提示。
     *
     * @return array<string, string> 校验规则与中文消息的映射
     */
    public function messages(): array
    {
        return [
            'name.required' => '商品名称不能为空',
            'name.unique' => '商品名称已存在',
            'price.required' => '价格不能为空',
            'price.numeric' => '价格必须是数字',
        ];
    }

    /**
     * 返回商品字段的中文名称
     *
     * 业务逻辑：把技术字段名转换为用户可理解的业务名称。
     *
     * @return array<string, string> 字段名与中文名称的映射
     */
    public function attributes(): array
    {
        return [
            'name' => '商品名称',
            'price' => '价格',
            'stock' => '库存',
        ];
    }

    private function rulesForIndex(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ];
    }

    private function rulesForShow(): array
    {
        return [];
    }

    private function rulesForSearch(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function rulesForStore(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(ProductModel::TABLE, 'name')],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    private function rulesForUpdate(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique(ProductModel::TABLE, 'name')->ignore($this->route('id')),
            ],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    private function rulesForDestroy(): array
    {
        return [];
    }

    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}
```

### 4.3 Service

- **位置**：`app/Services/{实体}Service.php`。
- **必须**：构造函数注入 Repository（**禁止直接注入 Model 或使用 `Model::` 查询**）；写操作使用 `DB::beginTransaction()/commit()/rollBack()`；业务规则不通过时**抛异常**（`ResponseHelper::errorIf()` 或 `throwError()`），**禁止返回 null 表达失败**；查询型方法返回 Model/集合，**禁止返回裸数组**。
- **禁止**：在 Service 里直接返回 `ResponseHelper::success(...)` 响应——那是 Controller 的职责。
- **数据不存在**：用 `ResponseHelper::errorIf(!$product, '商品不存在')`（一行最简洁，等价于 `if (!$product) { ResponseHelper::throwError('商品不存在'); }`），抛异常由全局异常处理统一转 400 + 业务码，**不返回 404**。

```php
<?php

namespace App\Services;

use App\Helpers\ResponseHelper;
use App\Models\ProductModel;
use App\Repositories\ProductRepository;
use Illuminate\Support\Facades\DB;

/**
 * 商品业务服务
 * 负责商品规则校验、事务编排，并通过商品仓储访问数据
 */
class ProductService
{
    /**
     * 注入商品仓储
     *
     * 业务逻辑：保存容器提供的商品仓储，作为商品数据的唯一访问入口。
     *
     * @param  ProductRepository  $productRepository  商品数据仓储
     * @return void
     */
    public function __construct(
        private ProductRepository $productRepository,
    ) {}

    /**
     * 创建商品
     *
     * 业务逻辑：在事务内校验名称唯一性并写入商品，任一步失败都回滚并继续抛出异常。
     *
     * @param  array<string, mixed>  $productData  已通过 FormRequest 校验的商品数据
     * @return ProductModel 创建完成的商品模型
     *
     * @throws \Throwable 商品名称重复或数据库写入失败时抛出
     */
    public function create(array $productData): ProductModel
    {
        DB::beginTransaction();
        try {
            // 名称重复会破坏商品识别规则，因此必须在写入前终止事务。
            ResponseHelper::errorIf(
                $this->productRepository->existsByName($productData['name']),
                '商品名称已存在'
            );

            $product = $this->productRepository->create($productData);

            DB::commit();

            return $product;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    /**
     * 根据 ID 获取商品
     *
     * 业务逻辑：查询商品；数据不存在时抛业务异常，由全局异常处理转换为统一响应。
     *
     * @param  int  $id  商品 ID
     * @return ProductModel 对应的商品模型
     *
     * @throws \Exception 商品不存在时抛出
     */
    public function getById(int $id): ProductModel
    {
        $product = $this->productRepository->findById($id);

        ResponseHelper::errorIf(!$product, '商品不存在');

        return $product;
    }
}
```

### 4.4 Repository（必需）

- **位置**：`app/Repositories/{实体}Repository.php`。
- **一一对应**：一张业务数据表只对应一个 Model 和一个 Repository；物理表 `km_admin_users` → `AdminUserModel` + `AdminUserRepository`。`$table`、关联表和查询条件使用去掉物理前缀后的完整逻辑名，物理前缀由连接配置追加。
- **必须**：构造函数只注入对应 Model（`private ProductModel $model`）；所有数据访问（含简单 `find`/`where`/`create`/`update`/`delete`/`paginate`）都在此封装；方法返回类型明确（`?Model` / `Collection` / `LengthAwarePaginator`）；复杂条件查询在 Repository 内组装 Eloquent Query Builder。
- **查询入口**：每个查询必须先将注入 Model 的 `newQuery()`（或 `query()`）赋值给 `$query`；关联查询先将关系方法赋值给 `$query`。禁止直接从 `Model::`、`$model->where()` 或其它对象继续查询。
- **查询格式**：禁止长链式调用；`where`、`orWhere`、`whereHas`、`orderBy`、`with`、`withCount`、`select`、`join`、`lockForUpdate` 等每次调用必须独立成行，并持续作用于 `$query`。
- **条件分支**：可选条件必须使用独立的 `if` 代码块判断后调用 `$query->where(...)` 或对应方法；禁止使用 `when`、三目运算或把条件嵌入链式调用。
- **终结方法**：`get()`、`first()`、`find()`、`exists()`、`paginate()`、`count()` 等终结方法必须单独作为查询最后一条语句执行，执行后不得继续修改该查询。
- **关联查询**：关联关系定义在 Model，Repository 通过对应 Model 的关系方法、作用域或 `whereHas` 查询；纯关联中间表不创建空壳 Model/Repository。Repository 禁止使用 `DB::table()`、`DB::query()`、`DB::connection()` 直接构造业务查询。
- **禁止**：按接口或流程创建 `AuthRepository`、`OrderFlowRepository` 等聚合仓储；禁止一个 Repository 注入多个实体 Model。
- **禁止**：Service/Controller 直接使用 `Model::` 查询或 Query Builder——那是 Repository 的职责。
- 排序字段、方向、分页大小必须白名单与限界，不能直接把请求参数传给 `orderBy()`。

```php
<?php

namespace App\Repositories;

use App\Models\ProductModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 商品数据仓储
 * 负责封装商品表的查询与写入，是商品数据访问的唯一入口
 */
class ProductRepository
{
    private const ALLOWED_SORTS = ['id', 'created_at', 'updated_at'];

    private const DEFAULT_SORT_BY = 'created_at';

    private const DEFAULT_SORT_ORDER = 'desc';

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * 注入商品模型
     *
     * 业务逻辑：保存容器提供的商品模型，用它创建每次独立的数据查询。
     *
     * @param  ProductModel  $model  商品模型
     * @return void
     */
    public function __construct(
        private ProductModel $model,
    ) {}

    /**
     * 根据 ID 查询商品
     *
     * 业务逻辑：使用商品主键执行单条查询，不存在时返回 null。
     *
     * @param  int  $id  商品 ID
     * @return ProductModel|null 查询到的商品或 null
     *
     * @throws \Throwable 数据库查询失败时抛出
     */
    public function findById(int $id): ?ProductModel
    {
        $query = $this->model->newQuery();
        $query->whereKey($id);

        return $query->first();
    }

    /**
     * 判断商品名称是否已存在
     *
     * 业务逻辑：执行存在性查询，供 Service 校验商品名称唯一性。
     *
     * @param  string  $name  商品名称
     * @return bool 已存在返回 true，否则返回 false
     *
     * @throws \Throwable 数据库查询失败时抛出
     */
    public function existsByName(string $name): bool
    {
        $query = $this->model->newQuery();
        $query->where('name', $name);

        return $query->exists();
    }

    /**
     * 创建商品记录
     *
     * 业务逻辑：通过商品模型的新查询写入已校验字段。
     *
     * @param  array<string, mixed>  $productData  已校验的商品数据
     * @return ProductModel 创建完成的商品模型
     *
     * @throws \Throwable 商品数据无法写入时抛出
     */
    public function create(array $productData): ProductModel
    {
        $query = $this->model->newQuery();

        return $query->create($productData);
    }

    /**
     * 获取启用商品分页列表
     *
     * 业务逻辑：只查询启用商品，并按最新创建时间倒序分页。
     *
     * @param  int  $perPage  每页商品数量
     * @return LengthAwarePaginator 商品分页结果
     *
     * @throws \Throwable 数据库查询失败时抛出
     */
    public function paginate(int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = $this->model->newQuery();
        $query->active();
        $query->latest();

        return $query->paginate($perPage);
    }

    /**
     * 按筛选条件搜索商品
     *
     * 业务逻辑：规范化排序与分页参数，再按可选关键词查询商品。
     *
     * @param  array{keyword?: string, sort_by?: string, sort_order?: string, per_page?: int}  $filters  商品筛选条件
     * @return LengthAwarePaginator 商品搜索分页结果
     *
     * @throws \Throwable 数据库查询失败时抛出
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $sortBy = $this->resolveSortBy($filters);
        $sortOrder = $this->resolveSortOrder($filters);
        $perPage = $this->resolvePerPage($filters);
        $keyword = (string) ($filters['keyword'] ?? '');

        $query = $this->model->newQuery();

        if ($keyword !== '') {
            $query->search($keyword);
        }

        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    private function resolveSortBy(array $filters): string
    {
        $requestedSort = (string) ($filters['sort_by'] ?? '');

        if (in_array($requestedSort, self::ALLOWED_SORTS, true)) {
            return $requestedSort;
        }

        return self::DEFAULT_SORT_BY;
    }

    private function resolveSortOrder(array $filters): string
    {
        if (($filters['sort_order'] ?? '') === 'asc') {
            return 'asc';
        }

        return self::DEFAULT_SORT_ORDER;
    }

    private function resolvePerPage(array $filters): int
    {
        $requestedPerPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return min(max($requestedPerPage, 1), self::MAX_PER_PAGE);
    }
}
```

### 4.5 Model（Eloquent 模型）

- **位置**：`app/Models/{实体}Model.php`，文件内类名必须为 `{实体}Model`。
- **一一对应**：每个 Model 只映射一张业务数据表；类名使用业务实体名，不携带 `km_admin_` 等物理表前缀。
- **必须**：用 `public const TABLE` 定义逻辑表名并通过 `$table = self::TABLE` 显式映射，同时声明 `$fillable`、`$casts`；定义关联关系；多值状态用 Enum，二元标记用 boolean；查询作用域用 `scopeXxx`，访问器用 `Attribute::make()`。
- **表名**：`TABLE` 常量必须与数据库逻辑表名完全一致，例如 `AdminUserModel::TABLE = 'admin_users'`；物理 `km_` 前缀由连接配置追加，避免依赖 Eloquent 推导和重复硬编码。
- 引用任何关联模型前确认其存在（Demo 的 `DemoOrderItem` 是反例）；`$table` 与数据库表一致。

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品数据模型
 * 负责商品表映射、商品关联、常用查询作用域和领域判断
 */
class ProductModel extends Model
{
    use HasFactory, SoftDeletes;

    public const TABLE = 'products';

    protected $table = self::TABLE;

    protected $fillable = ['name', 'description', 'price', 'stock', 'status'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'status' => 'boolean',
    ];

    /**
     * 获取商品关联的订单明细
     *
     * 业务逻辑：建立商品与订单明细的一对多关系，供 Repository 组合关联查询。
     *
     * @return HasMany 商品关联的订单明细关系
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItemModel::class);
    }

    /**
     * 限定查询只包含启用商品
     *
     * 业务逻辑：状态字段已转换为布尔值，true 表示商品可用。
     *
     * @param  Builder<ProductModel>  $query  商品查询构造器
     * @return Builder<ProductModel> 已追加启用条件的查询构造器
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * 限定查询只包含有库存商品
     *
     * 业务逻辑：库存大于零才允许进入有库存结果集。
     *
     * @param  Builder<ProductModel>  $query  商品查询构造器
     * @return Builder<ProductModel> 已追加库存条件的查询构造器
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock', '>', 0);
    }

    /**
     * 返回用于展示的商品价格
     *
     * @return Attribute 商品价格格式化访问器
     */
    protected function priceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn () => '¥' . number_format($this->price, 2),
        );
    }

    /**
     * 判断商品库存是否满足指定数量
     *
     * 业务逻辑：当前库存大于或等于需求数量时视为库存充足。
     *
     * @param  int  $quantity  需要扣减的商品数量
     * @return bool 库存充足返回 true，否则返回 false
     */
    public function hasStock(int $quantity): bool
    {
        return $this->stock >= $quantity;
    }
}
```

### 4.6 Enum

- **位置**：`app/Enums/{实体}Status.php`；推荐 `enum XxxStatus: string`。
- **必须**：提供 `label(): string` 中文标签；按需提供 `isXxx(): bool` 判断方法。

```php
<?php

namespace App\Enums;

/**
 * 用户状态枚举
 * 统一约束用户状态值，并提供面向用户的中文标签和状态判断
 */
enum UserStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case BANNED = 'banned';

    /**
     * 获取用户状态的中文标签
     *
     * 业务逻辑：把稳定的状态值转换为用户可理解的展示文案。
     *
     * @return string 当前状态的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => '激活',
            self::INACTIVE => '未激活',
            self::BANNED => '已封禁',
        };
    }

    /**
     * 判断用户是否处于激活状态
     *
     * 业务逻辑：仅 ACTIVE 状态允许被视为已激活。
     *
     * @return bool 已激活返回 true，否则返回 false
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
```

---

### 4.7 Cache（缓存层）

- **位置**：`app/Caches/CacheManager.php`、`app/Caches/CacheScope.php`。
- **职责**：`CacheManager` 注入 `Illuminate\Contracts\Redis\Factory` 并选择 connection；`CacheScope` 只提供 `connection()` 和 HMAC key。
- **调用**：Service 构造函数注入 `CacheManager`，通过 `$scope = $this->cache->scope('users')` 获取上下文，再直接调用 `$scope->connection()->get($scope->key($id))`；scope 和 connection 必须是代码常量或 Enum，禁止接收用户输入。
- **禁止**：业务创建 `XxxCache` 空壳类、手工拼接业务 key、`flushdb`/`flushall`；Repository 只负责数据库访问，不负责缓存 key。
- **原生语义**：`CacheScope` 不提供 `get/put/remember` 等包装。字符串写入优先使用 `SET ... EX/PX` 或 `SETEX`；JSON 编解码、Hash/Set 的 TTL、transaction/pipeline/Lua 和异常策略由业务明确处理。
- **并发**：幂等占位使用 `SET NX EX`；一次性消费、锁和限流使用 Redis 原子命令、Lua 或 Laravel Lock/RateLimiter，禁止把多条 Redis 命令误认为原子操作。
- **安全**：适配层逻辑 key 结构为 `namespace:version:scope:HMAC-SHA256(identifier)`；直接 Redis 的后端前缀来自 `REDIS_PREFIX`，`CACHE_PREFIX` 不作用于此路径。缓存值不等于加密；不记录原始标识、token 或值。
- **配置**：`config/cache.php` 的 `redis_connection`、`namespace`、`version`、`hash_key` 分别对应 `CACHE_REDIS_CONNECTION`、`CACHE_NAMESPACE`、`CACHE_VERSION`、`CACHE_HASH_KEY`；hash key 未设置时回退 `APP_KEY`。

## 5. 路由规范

1. **业务路由一律使用注解路由**，写在 Controller 方法上；`routes/api.php` 只放全局/兜底路由（如 `GET /api/health`）。⚠️ 路由扫描配置在 `config/route-attributes.php`，**仅扫描 `app/Http/Controllers/Api/` 目录下以 `*Controller.php` 结尾的文件**——新控制器必须放在 `app/Http/Controllers/Api/` 下才会生效。
2. **路径前缀**：`#[Prefix('api/{模块}')]`，模块名全小写复数（如 `api/products`）。Demo 示例若以 `Demo` 前缀命名，**仅用于示例，正式业务模块不要加 `demo`**。
3. **路由命名**：`api.{模块}.{动作}`，动作取 REST 语义：`index / show / store / update / destroy / search`（如 `api.products.index`）。
4. **REST 动作映射**：

| 动作 | 方法 | 路径 | 名称 |
|------|------|------|------|
| 列表 | GET | `/` | `api.{m}.index` |
| 搜索 | GET | `/search` | `api.{m}.search` |
| 详情 | GET | `/{id}` | `api.{m}.show` |
| 创建 | POST | `/` | `api.{m}.store` |
| 更新 | PUT/PATCH | `/{id}` | `api.{m}.update` |
| 删除 | DELETE | `/{id}` | `api.{m}.destroy` |

5. **需要认证的路由**按已确认需求在注解上声明认证与权限中间件。
6. 验证路由是否注册：`docker compose -f docker-compose.image.yml exec -T app php artisan route:list --path=api`（主机 PHP 7.4 不满足 `^8.3`，统一在容器内执行）。

---

## 6. 响应与异常规范

### 6.1 统一响应格式（ResponseHelper）

所有 API 响应结构：`{ "code": int, "message": string, "data": mixed }`，**code = 0 表示成功**，非 0 表示失败。

| 方法 | 说明 |
|------|------|
| `ResponseHelper::success($data, $message = 'success', $httpStatus = 200)` | 成功，code=0 |
| `ResponseHelper::error($message, $code = -1, $data = null, $httpStatus = 400)` | 失败，code 默认 -1 |
| `ResponseHelper::errorIf($cond, $message, $code)` | 条件成立即抛异常（Service 内校验用，**一行最简洁**） |
| `ResponseHelper::throwError($message, $code)` | 直接抛异常 |
| `ResponseHelper::response($data, $message, $code, $httpStatus)` | 通用响应（Controller 直接 return） |

**HTTP 状态码约定**：
- `200` 成功；`201` 创建成功；`400` 业务参数/规则错误（**含数据不存在**）；`401` 未认证/令牌无效；`404` 资源/路由不存在（框架级）；`422` 校验失败。

### 6.2 异常处理（重要）

- `bootstrap/app.php` 已配置全局异常渲染（见 6.3），**框架级异常（未认证、校验失败）无需在 Controller 处理**。
- **推荐写法（唯一）**：Service 内用 `ResponseHelper::errorIf(!$product, '商品不存在')` 抛异常 → 全局异常处理兜底，统一转 400 + 业务码。Controller 不写 try-catch，保持薄。
- **❌ 反例（禁止）**：数据不存在时在 Controller catch 后返回 `404` 或 `ApiCode::NOT_FOUND`——**数据不存在是业务错误，必须走业务异常（400 + 业务码）**；404 仅用于路由/资源不存在（框架级 `NotFoundHttpException`）。
- **兼容写法**（仅限现有 Demo 中 store/update/destroy 的 try-catch）：`catch (\Exception $e)` 后 `ResponseHelper::error($e->getMessage(), ...)`。**新增代码一律用推荐写法**。
- 业务异常 `$code` 使用 `App\Enums\ApiCode` 常量。

### 6.3 全局异常渲染（bootstrap/app.php，已配置，勿重复处理）

| 异常 | HTTP | 业务码 | 说明 |
|------|------|--------|------|
| `ValidationException`（api/*） | 422 | `INVALID_PARAMS` | 参数校验失败 |
| `AuthenticationException`（api/*） | 401 | `UNAUTHORIZED` | 未认证或令牌无效 |
| `NotFoundHttpException` | 404 | `NOT_FOUND` | 路由/资源不存在（框架级） |
| 其他 `Throwable` | 400 | `ERROR` 或异常携带的业务码 | 业务异常（数据不存在等） |

---

## 7. 命名约定（汇总）

| 对象 | 规范 | 示例 |
|--------------|-----------------------|-----------------------------------------|
| 类名 / 命名空间 | `PascalCase` | `ProductService`、`ProductRequest`、`ProductModel` |
| 方法 / 变量 | `camelCase`，名称必须直接表达业务意图 | `getUserActiveStatus()`、`$isEmailSent` |
| 表名 / 字段 | `snake_case` | `products`、`created_at` |
| 文件 | `PascalCase.php`（文件内类名必须同名，满足 PSR-4） | `ProductController.php` |
| Models 层文件 | `{实体}Model.php`，不携带物理表前缀 | `AdminUserModel.php` |
| 常量 / 枚举值 | `UPPER_SNAKE_CASE` | `ACTIVE` |
| 路由路径 | 小写复数 | `api/products` |
| 路由名称 | `api.{m}.{action}` | `api.products.index` |
| 控制器 | `{模块}Controller` | `ProductController` |
| Service | `{实体}Service` | `ProductService` |
| Repository | 与 Model/业务表一一对应的 `{实体}Repository` | `AdminUserRepository` |
| Request | `{实体}Request`，与 Controller 一一对应 | `ProductController` ↔ `ProductRequest` |
| 测试方法 | `test_` 前缀或 `#[Test]` | `test_valid_login` |
| Demo 示例 | `Demo` 前缀（仅示例代码） | `DemoProductModel` |

**全链路命名一致（红线）**：文件、类名、导入、依赖注入、路由前缀、路由名、Request 的 `unique/exists` 表名、测试必须使用同一业务实体名；物理表名仅出现在 Model `$table`、校验表名和必要查询中。

---

## 8. 可读性与代码风格（AI 必须遵守）

### 8.1 结构与命名

- **一个方法只做一件事**：Controller 方法仍以 10 行内为目标；其他方法尽量控制在 30 行内。超过 30 行必须按单一职责拆分，行数按方法体内的实际代码计算，不含空行与注释。
- **名字即注释**：类、方法、变量必须直接表达业务意图，禁止使用 `$flag`、`$temp`、`$data1`、`processData()` 等模糊名称。
- **命名统一**：类名使用 `PascalCase`；方法名和 PHP 变量使用 `camelCase`。数据库字段、请求字段可保持既有 `snake_case`，但承接它们的 PHP 变量不得改成 `snake_case`。
- **类型明确**：方法参数与返回值尽量声明类型（`int $id`、`: JsonResponse`）；查询结果、集合和分页对象不得用含糊的 `mixed` 代替可确定类型。

### 8.2 注释

- **类注释必须写**：每个 PHP 类、Enum、Trait、Interface 都必须在声明上方使用中文 PHPDoc，说明业务用途和职责边界，不能只重复类名。
- **公开方法注释必须写**：包括构造函数。PHPDoc 必须说明业务逻辑，并用 `@param` 逐项解释输入、用 `@return` 解释返回值；可能抛异常时必须写准确的 `@throws`。不主动抛异常时无需虚构 `@throws`。
- **注释解释“为什么”**：算法、正则、兼容处理和特殊业务边界必须在相关代码上方写内联注释，说明业务原因；禁止写 `// 循环数组`、`// 查询数据` 等语法复述。
- **注释必须同步**：代码行为、参数、返回值或异常发生变化时，必须同时更新 PHPDoc；失真的注释按缺陷处理。

公开方法 PHPDoc 统一格式：

```php
/**
 * 检查用户是否有权限操作订单
 *
 * 业务逻辑：确认订单存在后，校验订单归属与当前用户权限。
 *
 * @param  int  $userId  当前登录用户 ID
 * @param  int  $orderId  目标订单 ID
 * @return bool 有权限返回 true，否则返回 false
 *
 * @throws \Exception 订单不存在时抛出
 */
```

### 8.3 控制流、异常与配置

- **优先早返回**：不满足继续执行条件时立即 `return` 或 `continue`；出现第 3 层 `if` 嵌套前必须提取方法或反转条件。
- **禁止静默失败**：禁止使用 `@` 屏蔽错误，禁止空 `catch`。异常只能在当前层能够补充业务处理时捕获，否则明确向上抛出并由既有全局异常机制处理。
- **配置必须显式**：数据库表名、第三方 API 超时时间、HTTP 状态码和多值业务状态不得在方法内写魔法值。表名由 Model 的 `TABLE` 常量统一提供，HTTP 状态码使用框架常量，多值业务状态使用 Enum，二元标记使用 boolean，环境差异使用配置文件或环境变量。
- **常量位置固定**：类常量写在类声明后的顶部，再写属性、构造函数和业务方法。

### 8.4 数组与集合逻辑

- **键名必须有语义**：业务关联数组使用明确的字符串键；禁止依赖 `$data[0]`、`$items[1]` 等位置猜测含义。有序列表使用 `foreach` 或集合接口遍历，并把当前项赋给语义化变量。
- **复杂逻辑用清晰循环**：禁止嵌套多层 `array_map()`、`array_filter()` 或难以追踪的回调组合；存在分支、状态变化或异常处理时使用 `foreach`，并只为特殊业务原因写注释。

### 8.5 AI 编码输出流程

1. **复述理解**：开始实现前用 2～3 句话确认需求目标、范围和关键约束。
2. **分块编写**：新增业务代码按“配置/常量 → 核心业务方法 → 调用示例”展示；调用示例放在测试、接口请求示例或文档中，禁止把无用示例代码写入生产目录。
3. **注释自查**：完成后说明关键注释的位置及其业务原因，并检查类注释、公开方法 PHPDoc、特殊边界注释是否与实现一致。

### 8.6 基础格式

- **强制工具**：提交前必须运行 `docker compose -f docker-compose.image.yml exec -T app vendor/bin/pint --test` 校验（已配置 Laravel Pint）。
- **编码**：UTF-8、LF、4 空格缩进、文件末尾换行（见 `.editorconfig`）。
- **use 排序**：`App\` 内部类在前，第三方包（`Spatie\`、`Illuminate\`）在后，按字母序（Pint 会自动处理）。
- **错误消息**：所有对用户可见的消息使用中文。

---

## 9. 数据库规范

- 数据库表、字段、索引、外键及默认值由开发者在数据库侧人工建立和维护，应用不负责结构变更。
- 表名使用小写复数；Model 使用对应逻辑表名的 `public const TABLE`，物理 `km_` 前缀由连接配置追加；**业务表禁止使用 `demo_` 前缀**（该前缀仅限示例）。
- 每张表应有主键 `id`、`created_at`/`updated_at`；需要软删除时增加 `deleted_at`。
- 外键/索引根据查询需要维护；关联字段命名 `{实体}_id`。
- 状态类字段取值用 Enum 管理，默认值在数据库侧维护。

### 9.1 双数据库规范

- 主业务库连接名固定为 `mysql`；第二数据库连接名固定为 `data_bridge`。
- 主库使用 `DB_*` 配置，第二库使用 `DATA_BRIDGE_DB_*` 配置；业务代码禁止直接读取环境变量。
- 涉及双库的每个 Model 必须声明固定的 `$connection`：A 库为 `mysql`，B 库为 `data_bridge`；禁止根据请求参数动态切换连接。
- 每张表仍只对应一个 Model 和一个 Repository；Repository 只能注入所属连接的对应 Model。
- Service 通过多个 Repository 编排跨库流程；Service/Controller 禁止直接使用 `Model::`、`DB::table()` 或 Query Builder 查询业务表。
- 不使用跨库 SQL JOIN；需要组合结果时，分别查询后由 Service 按业务键组装。
- 事务必须显式指定连接：`DB::connection('mysql')->transaction(...)` 或 `DB::connection('data_bridge')->transaction(...)`。一个连接的提交/回滚不会自动影响另一个连接。
- 跨库写入不使用 XA/两阶段提交；必须使用业务唯一键保证幂等，并设计失败重试或补偿。
- B 库表的前缀由 `DATA_BRIDGE_DB_PREFIX` 配置；Model 的 `TABLE` 和 `$table` 只写逻辑表名。

---

## 10. 测试规范

- **位置**：HTTP / 数据库 / JSON 契约测试 → `tests/Feature/`（按模块分子目录，如 `tests/Feature/Product/`）；纯逻辑（无 I/O）→ `tests/Unit/`。
- **命名**：文件 `*Test.php`（`PascalCase`）；方法 `test_` 前缀（如 `test_valid_login`）。
- **必须使用** `RefreshDatabase`；构造测试数据用 Factory（`AdminUserModel::factory()->create([...])`）。
- **断言**：`assertStatus()` + `assertJsonStructure()` + `assertJson()`，校验 code/message/data 契约。
- **覆盖要求**：每个新接口必须覆盖 ①成功场景 ②校验失败（422）③关键错误状态（401/404/400）；合并前运行完整测试套件。
- **隔离**：`phpunit.xml` 为 `mysql` 和 `data_bridge` 两个连接分别使用 SQLite 内存库，另使用 array cache/session、sync queue；测试**不得连接外部开发/生产数据库**。
- **运行**：`docker compose -f docker-compose.image.yml exec -T app php artisan test --no-coverage`；只跑单个文件 `php artisan test --filter=ApiCodeTest`。

---

## 11. 新功能开发流程（SOP）

新增一个业务模块（以 `Product` 为例），按以下顺序执行：

```bash
# 1. 在数据库侧建立业务表结构

# 2. 模型（含 fillable/casts/作用域/关联/枚举引用）
docker compose -f docker-compose.image.yml exec -T app php artisan make:model ProductModel
#    生成后设置 public const TABLE = 'products'、protected $connection = 'mysql' 和 protected $table = self::TABLE
#    B 库实体将 protected $connection 改为 'data_bridge'

# 3. 枚举（状态类字段）
#    app/Enums/ProductStatus.php

# 4. Request（每个 Controller 对应一个同实体 Request）
docker compose -f docker-compose.image.yml exec -T app php artisan make:request ProductRequest

# 5. Repository（必需层，封装所有数据访问）
#    app/Repositories/ProductRepository.php
#    与 ProductModel 一一对应，只注入 ProductModel

# 6. Service（业务逻辑 + 事务 + 抛异常）
#    app/Services/ProductService.php

# 7. Controller（注解路由，放 Api 目录）
docker compose -f docker-compose.image.yml exec -T app php artisan make:controller Api/Product/ProductController

# 8. 测试（Feature 下按模块建目录）
#    tests/Feature/Product/ProductTest.php
```

完成后自查清单：
- [ ] Controller 在 `app/Http/Controllers/Api/` 下，方法 < 10 行，无业务逻辑，直接 return ResponseHelper
- [ ] Controller 与 Request 按实体一一对应，所有路由动作都注入同一个 Request
- [ ] Request 的 `rules()` 覆盖全部 Controller 动作，并分发到 `rulesFor{Action}()`；未知动作失败关闭
- [ ] 每张业务表对应唯一 `{实体}Model` 和 `{实体}Repository`，文件名不携带物理表前缀
- [ ] Repository 只注入对应 Model，所有数据访问（含 CRUD）都走 Repository
- [ ] 关联关系定义在 Model，并由对应 Repository 调用
- [ ] Service/Controller 中无直接 `Model::` 查询或 Query Builder
- [ ] 双库实体已在 Model 固定声明 `mysql` 或 `data_bridge` 连接，未从请求参数动态选择
- [ ] 跨库流程通过多个 Repository 编排，事务显式指定连接，并具备幂等键/重试或补偿策略
- [ ] 写操作走 Service + 事务，失败抛异常；数据不存在用 `errorIf`，不返回 404
- [ ] 全部通过 `ResponseHelper` 返回 `code/message/data`
- [ ] 路由命名 `api.{模块}.{action}`，`php artisan route:list --path=api` 可见
- [ ] FormRequest 含中文 messages/attributes
- [ ] 每个类有职责明确的中文 PHPDoc；每个公开方法完整说明输入、业务逻辑、返回值和可能异常
- [ ] Controller 方法不超过 10 行，其他方法不超过 30 行；命名明确且无 3 层 `if` 嵌套
- [ ] 无 `@`、空 `catch`、魔法表名/超时/状态码；表名、状态和环境差异来自常量、Enum 或配置
- [ ] 业务数组使用语义化键名，复杂分支未使用多层数组回调隐藏逻辑
- [ ] 测试覆盖成功/校验失败/关键错误 3 类场景且全部通过
- [ ] `vendor/bin/pint --test` 校验通过
- [ ] 完整测试套件通过（`php artisan test --no-coverage`）

---

## 12. 提交与 PR 规范

- **格式**：Conventional Commits，简洁中文/英文均可，示例：
  - `feat: add product endpoint`
  - `fix: validate stock`
  - `refactor: extract ProductService`
  - `test: cover login failure cases`
- **要求**：一次提交只解决一个目的；**禁止提交** `.env`、密钥、生产数据、`vendor/`、构建产物；提交前删除无用调试代码。
- **PR 描述必须包含**：变更范围、验证命令、接口请求/响应示例、关联 issue（如有）。纯 API 变更无需页面截图。

---

## 13. 安全红线

- 不提交、不打印、不写入日志：`.env`、密钥、token、用户密码等敏感信息。
- 日志不得记录密码、Authorization、Cookie、Token、完整数据库 URL、Redis 凭据、敏感数据或完整请求体。
- 密码字段使用 `$casts => ['password' => 'hashed']`，禁止明文存储。
- 输出用户数据前注意 `Hidden`/`hidden` 隐藏敏感字段（密码、remember_token）。
- 修改数据库结构或数据须由开发者在数据库侧明确执行，AI 不得擅自执行破坏性命令。
- 排序字段、排序方向、分页大小必须白名单与限界，不得直接把请求参数传给 `orderBy()`。

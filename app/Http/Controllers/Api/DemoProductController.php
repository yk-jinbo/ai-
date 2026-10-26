<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\DemoProductRequest;
use App\Services\DemoProductService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Delete;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Spatie\RouteAttributes\Attributes\Put;
use Spatie\RouteAttributes\Attributes\WhereNumber;

#[Prefix('api/products')]
class DemoProductController extends Controller
{
    public function __construct(
        private DemoProductService $productService,
    ) {}

    /**
     * 获取商品列表
     */
    #[Get('/', name: 'api.products.index')]
    public function index(DemoProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        return ResponseHelper::success(
            $this->productService->getPaginated((int) ($data['per_page'] ?? 15)),
        );
    }

    /**
     * 搜索商品
     */
    #[Get('/search', name: 'api.products.search')]
    public function search(DemoProductRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->productService->search($request->validated()),
            '搜索成功',
        );
    }

    /**
     * 获取商品详情
     *
     * 数据不存在由 Service 抛异常（ResponseHelper::errorIf），全局异常处理统一转 JSON，
     * Controller 保持薄层，不返回 404。
     */
    #[Get('/{id}', name: 'api.products.show')]
    #[WhereNumber('id')]
    public function show(DemoProductRequest $request, int $id): JsonResponse
    {
        return ResponseHelper::success($this->productService->getById($id));
    }

    /**
     * 创建商品
     */
    #[Post('/', name: 'api.products.store')]
    public function store(DemoProductRequest $request): JsonResponse
    {
        return ResponseHelper::success(
            $this->productService->create($request->validated()),
            '商品创建成功',
            201,
        );
    }

    /**
     * 更新商品
     */
    #[Put('/{id}', name: 'api.products.update')]
    #[WhereNumber('id')]
    public function update(DemoProductRequest $request, int $id): JsonResponse
    {
        return ResponseHelper::success(
            $this->productService->update($id, $request->validated()),
            '商品更新成功',
        );
    }

    /**
     * 删除商品
     */
    #[Delete('/{id}', name: 'api.products.destroy')]
    #[WhereNumber('id')]
    public function destroy(DemoProductRequest $request, int $id): JsonResponse
    {
        $this->productService->delete($id);

        return ResponseHelper::success(null, '商品删除成功');
    }
}

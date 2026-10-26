<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use App\Models\DemoProductModel;
use App\Repositories\DemoProductRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class DemoProductService
{
    public function __construct(
        private DemoProductRepository $productRepository,
    ) {}

    /**
     * 获取分页商品列表。
     */
    public function getPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return $this->productRepository->paginate($perPage);
    }

    /**
     * 搜索商品。
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return $this->productRepository->search($filters);
    }

    /**
     * 根据 ID 获取商品，数据不存在按业务错误处理。
     */
    public function getById(int $id): DemoProductModel
    {
        $product = $this->productRepository->findById($id);

        ResponseHelper::errorIf(! $product, '商品不存在');

        return $product;
    }

    /**
     * 创建商品。
     */
    public function create(array $data): DemoProductModel
    {
        DB::beginTransaction();

        try {
            ResponseHelper::errorIf(
                $this->productRepository->existsByName((string) $data['name']),
                '商品名称已存在',
                ApiCode::CONFLICT,
            );

            $product = $this->productRepository->create($data);

            DB::commit();

            return $product;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新商品。
     */
    public function update(int $id, array $data): DemoProductModel
    {
        $product = $this->getById($id);

        DB::beginTransaction();

        try {
            if (isset($data['name'])) {
                ResponseHelper::errorIf(
                    $this->productRepository->existsByName((string) $data['name'], $id),
                    '商品名称已存在',
                    ApiCode::CONFLICT,
                );
            }

            $product = $this->productRepository->update($product, $data);

            DB::commit();

            return $product;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除商品。
     */
    public function delete(int $id): bool
    {
        $product = $this->getById($id);

        DB::beginTransaction();

        try {
            $deleted = $this->productRepository->delete($product);

            DB::commit();

            return $deleted;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 检查库存。
     */
    public function checkStock(int $productId, int $quantity): bool
    {
        ResponseHelper::errorIf($quantity < 1, '库存数量必须大于0', ApiCode::INVALID_PARAMS);

        return $this->getById($productId)->hasStock($quantity);
    }

    /**
     * 扣减库存，整个批次在同一事务中完成。
     */
    public function deductStock(array $items): void
    {
        DB::beginTransaction();

        try {
            foreach ($items as $item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                ResponseHelper::errorIf($quantity < 1, '库存数量必须大于0', ApiCode::INVALID_PARAMS);

                $product = $this->getById((int) $item['product_id']);
                ResponseHelper::errorIf(
                    $this->productRepository->decrementStock($product, $quantity) !== 1,
                    "商品 {$product->name} 库存不足",
                );
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 恢复库存。
     */
    public function restoreStock(int $productId, int $quantity): void
    {
        ResponseHelper::errorIf($quantity < 1, '库存数量必须大于0', ApiCode::INVALID_PARAMS);

        DB::beginTransaction();

        try {
            $this->productRepository->incrementStock($this->getById($productId), $quantity);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 批量检查库存。
     */
    public function batchCheckStock(array $items): bool
    {
        foreach ($items as $item) {
            if (! $this->checkStock((int) $item['product_id'], (int) $item['quantity'])) {
                return false;
            }
        }

        return true;
    }
}

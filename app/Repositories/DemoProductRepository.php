<?php

namespace App\Repositories;

use App\Models\DemoProductModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class DemoProductRepository
{
    public function __construct(
        private DemoProductModel $model,
    ) {}

    /**
     * 根据 ID 查找商品。
     */
    public function findById(int $id): ?DemoProductModel
    {
        $query = $this->model->newQuery();

        return $query->find($id);
    }

    /**
     * 按名称检查商品是否已存在，可排除指定商品。
     */
    public function existsByName(string $name, ?int $ignoreId = null): bool
    {
        $query = $this->model->newQuery();
        $query->where('name', $name);

        if ($ignoreId !== null) {
            $query->where($this->model->getKeyName(), '!=', $ignoreId);
        }

        return $query->exists();
    }

    /**
     * 创建商品。
     */
    public function create(array $data): DemoProductModel
    {
        $query = $this->model->newQuery();
        $product = $query->create($data);

        return $product->fresh();
    }

    /**
     * 更新商品并返回最新模型。
     */
    public function update(DemoProductModel $product, array $data): DemoProductModel
    {
        $product->update($data);

        return $product->fresh();
    }

    /**
     * 删除商品。
     */
    public function delete(DemoProductModel $product): bool
    {
        return (bool) $product->delete();
    }

    /**
     * 增加库存。
     */
    public function incrementStock(DemoProductModel $product, int $quantity): int
    {
        $query = $this->model->newQuery();
        $query->whereKey($product->getKey());

        return $query->increment('stock', $quantity);
    }

    /**
     * 扣减库存。
     */
    public function decrementStock(DemoProductModel $product, int $quantity): int
    {
        $query = $this->model->newQuery();
        $query->whereKey($product->getKey());
        $query->where('stock', '>=', $quantity);

        return $query->decrement('stock', $quantity);
    }

    /**
     * 获取上架商品分页列表。
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();
        $query->active();
        $query->latest();

        return $query->paginate($this->normalizePerPage($perPage));
    }

    /**
     * 按条件搜索商品，排序字段和分页大小使用白名单与边界保护。
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (filled($filters['keyword'] ?? null)) {
            $query->search((string) $filters['keyword']);
        }

        if (($filters['min_price'] ?? null) !== null && $filters['min_price'] !== '') {
            $query->where('price', '>=', (float) $filters['min_price']);
        }

        if (($filters['max_price'] ?? null) !== null && $filters['max_price'] !== '') {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        if ((bool) ($filters['in_stock'] ?? false)) {
            $query->inStock();
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', (bool) $filters['status']);
        }

        $allowedSorts = ['id', 'name', 'price', 'stock', 'created_at', 'updated_at'];
        $requestedSort = $filters['sort_by'] ?? 'created_at';
        $sortBy = in_array($requestedSort, $allowedSorts, true)
            ? $requestedSort
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($this->normalizePerPage((int) ($filters['per_page'] ?? 15)));
    }

    /**
     * 批量查找商品。
     */
    public function findByIds(array $ids): Collection
    {
        $query = $this->model->newQuery();
        $query->whereIn('id', $ids);

        return $query->get();
    }

    /**
     * 获取热门商品示例列表。
     */
    public function getPopular(int $limit = 10): Collection
    {
        $query = $this->model->newQuery();
        $query->active();
        $query->inStock();
        $query->latest();
        $query->limit(min(max($limit, 1), 100));

        return $query->get();
    }

    private function normalizePerPage(int $perPage): int
    {
        return min(max($perPage, 1), 100);
    }
}

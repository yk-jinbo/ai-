<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemoProductModel extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 表名
     */
    protected $table = 'demo_products';

    /**
     * 可批量赋值的属性
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'stock',
        'image',
        'status',
    ];

    /**
     * 属性类型转换
     */
    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'status' => 'boolean',
    ];

    /**
     * 默认值
     */
    protected $attributes = [
        'status' => 1,
        'stock' => 0,
    ];

    // ==================== 访问器 ====================

    /**
     * 图片完整 URL
     */
    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value ? asset("storage/{$value}") : null,
        );
    }

    /**
     * 价格格式化
     */
    protected function priceFormatted(): Attribute
    {
        return Attribute::make(
            get: fn (): string => '¥'.number_format((float) $this->price, 2),
        );
    }

    // ==================== 查询作用域 ====================

    /**
     * 只查询上架商品
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    /**
     * 按名称搜索
     */
    public function scopeSearch(Builder $query, string $keyword): Builder
    {
        return $query->where(function (Builder $query) use ($keyword): void {
            $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('description', 'like', "%{$keyword}%");
        });
    }

    /**
     * 有库存的商品
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock', '>', 0);
    }

    // ==================== 业务方法 ====================

    /**
     * 检查是否有足够库存
     */
    public function hasStock(int $quantity): bool
    {
        return $this->stock >= $quantity;
    }

    /**
     * 检查是否上架
     */
    public function isActive(): bool
    {
        return (bool) $this->status;
    }
}

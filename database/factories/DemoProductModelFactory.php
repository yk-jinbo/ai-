<?php

namespace Database\Factories;

use App\Models\DemoProductModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoProductModel>
 */
class DemoProductModelFactory extends Factory
{
    protected $model = DemoProductModel::class;

    /**
     * 定义 Demo 商品的默认测试数据。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 1, 9999),
            'stock' => fake()->numberBetween(0, 100),
            'image' => null,
            'status' => true,
        ];
    }

    /**
     * 设置为下架状态。
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }

    /**
     * 设置为无库存状态。
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => 0,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\AdminRoleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminRoleModel>
 */
class AdminRoleModelFactory extends Factory
{
    protected $model = AdminRoleModel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->jobTitle(),
            'description' => fake()->sentence(),
            'is_super_admin' => false,
        ];
    }

    /**
     * 超级管理员角色
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'super_admin',
            'name' => '超级管理员',
            'is_super_admin' => true,
        ]);
    }
}

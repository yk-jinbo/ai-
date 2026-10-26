<?php

namespace Database\Factories;

use App\Models\AdminPermissionModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminPermissionModel>
 */
class AdminPermissionModelFactory extends Factory
{
    protected $model = AdminPermissionModel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}

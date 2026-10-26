<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\AdminUserModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AdminUserModel>
 */
class AdminUserModelFactory extends Factory
{
    protected $model = AdminUserModel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('138########'),
            'email' => fake()->unique()->safeEmail(),
            'real_name' => fake()->name(),
            'status' => UserStatus::ACTIVE,
            'last_login_at' => null,
        ];
    }

    /**
     * 激活状态
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::ACTIVE,
        ]);
    }

    /**
     * 禁用状态
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::DISABLED,
        ]);
    }

    /**
     * 封禁状态
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::BANNED,
        ]);
    }
}

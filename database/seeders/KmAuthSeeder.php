<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Repositories\AdminRoleRepository;
use App\Repositories\AdminUserRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KmAuthSeeder extends Seeder
{
    public function __construct(
        private AdminUserRepository $userRepository,
        private AdminRoleRepository $roleRepository,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 幂等性：如果已存在则跳过
        if ($this->userRepository->existsByUsername('admin')) {
            $this->command?->info('超级管理员账号已存在，跳过初始化。');

            return;
        }

        $initialPassword = config('auth.km_initial_password');

        if (! is_string($initialPassword) || $initialPassword === '') {
            throw new RuntimeException('KM_ADMIN_INITIAL_PASSWORD 未配置，无法初始化超级管理员。');
        }

        DB::transaction(function () use ($initialPassword): void {
            $superAdminRole = $this->roleRepository->firstOrCreate(
                ['code' => 'super_admin'],
                [
                    'name' => '超级管理员',
                    'description' => '拥有系统所有权限',
                    'is_super_admin' => true,
                ]
            );

            $admin = $this->userRepository->create([
                'username' => 'admin',
                'password' => $initialPassword,
                'phone' => '13800000000',
                'email' => 'admin@example.com',
                'real_name' => '系统管理员',
                'status' => UserStatus::ACTIVE,
            ]);

            $this->userRepository->attachRole($admin, $superAdminRole);
        });

        $this->command?->info('超级管理员账号初始化完成。');
    }
}

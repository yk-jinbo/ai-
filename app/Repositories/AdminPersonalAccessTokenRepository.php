<?php

namespace App\Repositories;

use App\Models\AdminPersonalAccessTokenModel;
use App\Models\AdminUserModel;
use DateTimeInterface;
use Laravel\Sanctum\NewAccessToken;

class AdminPersonalAccessTokenRepository
{
    public function __construct(
        private AdminPersonalAccessTokenModel $model,
    ) {}

    /**
     * 撤销账号的全部凭证。
     */
    public function revokeAllFor(AdminUserModel $user)
    {
        $query = $this->model->newQuery();
        $query->where('tokenable_type', $user->getMorphClass());
        $query->where('tokenable_id', $user->getKey());

        return $query->delete();
    }

    /**
     * 签发新凭证。
     */
    public function issueFor(AdminUserModel $user, DateTimeInterface $expiresAt): NewAccessToken
    {
        return $user->createToken('auth_token', ['*'], $expiresAt);
    }

    /**
     * 撤销当前请求使用的凭证。
     */
    public function revokeCurrentFor(AdminUserModel $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof AdminPersonalAccessTokenModel) {
            $query = $this->model->newQuery();
            $query->whereKey($token->getKey());
            $query->delete();
        }
    }
}

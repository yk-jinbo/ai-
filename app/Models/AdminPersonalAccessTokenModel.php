<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class AdminPersonalAccessTokenModel extends SanctumPersonalAccessToken
{
    /**
     * 自定义表名
     */
    protected $table = 'admin_personal_access_tokens';

    protected $hidden = [
        'token',
    ];
}

<?php

namespace App\Repositories;

use App\Models\MemberModel;
use Illuminate\Database\Eloquent\Builder;

class MemberRepository
{
    public function __construct(private MemberModel $model) {}

    /** 按手机号或数字会员 ID 查询登录用户。 */
    public function findForLogin(string $identifier): ?MemberModel
    {
        return $this->model->newQuery()
            ->where(function (Builder $query) use ($identifier): void {
                $query->where('mobile', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }
}

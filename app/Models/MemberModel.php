<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberModel extends Model
{
    use SoftDeletes;

    protected $table = 'member';

    /**
     * 可批量赋值字段。
     */
    protected $fillable = [
        'parent_member_id',
        'name',
        'nickname',
        'mobile',
        'id_card',
        'type',
    ];

    /**
     * 字段类型转换。
     */
    protected $casts = [
        'id' => 'integer',
        'parent_member_id' => 'integer',
        'type' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 不向接口输出敏感及内部字段。
     */
    protected $hidden = [
        'id_card',
        'deleted_at',
    ];
}

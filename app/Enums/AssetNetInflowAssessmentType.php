<?php

namespace App\Enums;

/**
 * 资产净流入考核类型枚举
 * 限定净流入记录支持的组织考核范围
 */
enum AssetNetInflowAssessmentType: string
{
    case OPERATION_CENTER = 'OPERATION_CENTER';
    case BRANCH_COMPANY = 'BRANCH_COMPANY';
    case INCUBATION = 'INCUBATION';

    /**
     * 获取净流入考核类型的中文标签
     *
     * @return string 当前考核类型的中文标签
     */
    public function label(): string
    {
        return match ($this) {
            self::OPERATION_CENTER => '运营中心',
            self::BRANCH_COMPANY => '分公司',
            self::INCUBATION => '运营中心孵化',
        };
    }
}

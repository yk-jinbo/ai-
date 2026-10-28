<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * 关系 Demo 接口请求校验器。
 * 负责统一校验关系列表接口的分页参数，并按控制器动作分发规则。
 */
class RelationRequest extends FormRequest
{
    /**
     * Demo 接口暂不要求认证，允许请求进入参数校验。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 根据当前控制器动作返回分页校验规则。
     *
     * @return array<string, array<int, string>> 当前动作的校验规则
     *
     * @throws LogicException 未配置的控制器动作
     */
    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'fansList', 'teamList' => $this->paginationRules(),
            'teamStatistics', 'exchangeStatistics', 'exchangeStatisticsList', 'fansStatistics' => [],
            default => throw new LogicException('RelationRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回关系接口的中文校验消息。
     */
    public function messages(): array
    {
        return [
            'page.integer' => '页码必须是整数',
            'page.min' => '页码必须大于0',
            'pagesize.integer' => '每页数量必须是整数',
            'pagesize.min' => '每页数量至少为1',
            'pagesize.max' => '每页数量不能超过100',
        ];
    }

    /**
     * 返回关系接口的中文字段名称。
     */
    public function attributes(): array
    {
        return [
            'page' => '页码',
            'pagesize' => '每页数量',
        ];
    }

    /**
     * 返回列表接口分页规则。
     *
     * @return array<string, array<int, string>> 分页字段校验规则
     */
    private function paginationRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'pagesize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 获取当前路由绑定的控制器动作。
     */
    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

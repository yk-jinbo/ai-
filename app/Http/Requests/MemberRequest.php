<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * 会员接口请求校验器
 * 按会员控制器动作明确约束前端请求参数
 */
class MemberRequest extends FormRequest
{
    /**
     * 允许已通过路由认证的会员请求进入参数校验
     *
     * @return bool 始终返回 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 返回当前会员接口动作的校验规则
     *
     * @return array<string, mixed> 当前动作的字段校验规则
     *
     * @throws LogicException 当前动作未配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ((string) $this->route()?->getActionMethod()) {
            'getIdentities' => [],
            default => throw new LogicException('MemberRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /** @return array<string, string> 当前接口无额外字段校验消息 */
    public function messages(): array
    {
        return [];
    }

    /** @return array<string, string> 当前接口无额外字段名称 */
    public function attributes(): array
    {
        return [];
    }
}

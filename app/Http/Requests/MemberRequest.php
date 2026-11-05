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
        return match ($this->controllerAction()) {
            'getIdentities' => [],
            'operationCenters' => $this->rulesForOperationCenters(),
            default => throw new LogicException('MemberRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回会员接口字段的中文校验消息
     *
     * 业务逻辑：为运营中心查询的必填标识、日期和分页规则提供稳定的用户提示。
     *
     * @return array<string, string> 校验规则与中文消息的映射
     */
    public function messages(): array
    {
        return [
            'branchCompanyId.required' => '分公司 ID 不能为空',
            'branchCompanyId.string' => '分公司 ID 必须是字符串',
            'branchCompanyId.max' => '分公司 ID 不能超过 50 个字符',
            'asOfDate.date_format' => '统计日期格式必须为 YYYY-MM-DD',
            'pageNo.integer' => '页码必须是整数',
            'pageNo.min' => '页码必须从 1 开始',
            'pageSize.integer' => '每页数量必须是整数',
            'pageSize.min' => '每页数量至少为 1',
            'pageSize.max' => '每页数量不能超过 100',
        ];
    }

    /**
     * 返回会员接口字段的中文名称
     *
     * 业务逻辑：把第三方接口字段转换为用户可理解的业务名称。
     *
     * @return array<string, string> 字段名与中文名称的映射
     */
    public function attributes(): array
    {
        return [
            'branchCompanyId' => '分公司 ID',
            'asOfDate' => '统计日期',
            'pageNo' => '页码',
            'pageSize' => '每页数量',
        ];
    }

    /** @return array<string, mixed> 下属运营中心查询字段校验规则 */
    private function rulesForOperationCenters(): array
    {
        return [
            'branchCompanyId' => ['required', 'string', 'max:50'],
            'asOfDate' => ['nullable', 'date_format:Y-m-d'],
            'pageNo' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return string 当前路由绑定的控制器动作名称 */
    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

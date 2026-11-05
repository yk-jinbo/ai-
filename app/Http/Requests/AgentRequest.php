<?php

namespace App\Http\Requests;

use App\Enums\AgentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * 代理接口请求校验器
 * 按代理控制器动作约束收益日期、分页和可选筛选参数
 */
class AgentRequest extends FormRequest
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
     * 返回当前代理接口动作的校验规则
     *
     * @return array<string, mixed> 当前动作的字段校验规则
     *
     * @throws LogicException 当前动作未配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'getEarnings' => $this->rulesForGetEarnings(),
            default => throw new LogicException('AgentRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回代理收益字段的中文校验消息
     *
     * @return array<string, string> 校验规则与中文消息的映射
     */
    public function messages(): array
    {
        return [
            'fromDate.date_format' => '收益起始日期格式必须为 YYYY-MM-DD',
            'toDate.date_format' => '收益截止日期格式必须为 YYYY-MM-DD',
            'toDate.after_or_equal' => '收益截止日期不能早于起始日期',
            'pageNo.integer' => '页码必须是整数',
            'pageNo.min' => '页码必须从1开始',
            'pageSize.integer' => '每页数量必须是整数',
            'pageSize.min' => '每页数量至少为1',
            'pageSize.max' => '每页数量不能超过100',
            'agentType.enum' => '代理类型不正确',
            'regionCode.string' => '区域编码必须是字符串',
            'regionCode.max' => '区域编码不能超过50个字符',
            'agentOrderNo.string' => '代理订单号必须是字符串',
            'agentOrderNo.max' => '代理订单号不能超过100个字符',
        ];
    }

    /**
     * 返回代理收益字段的中文名称
     *
     * @return array<string, string> 字段名与中文名称的映射
     */
    public function attributes(): array
    {
        return [
            'fromDate' => '收益起始日期',
            'toDate' => '收益截止日期',
            'pageNo' => '页码',
            'pageSize' => '每页数量',
            'agentType' => '代理类型',
            'regionCode' => '区域编码',
            'agentOrderNo' => '代理订单号',
        ];
    }

    /** @return array<string, mixed> 代理收益查询字段校验规则 */
    private function rulesForGetEarnings(): array
    {
        $toDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($this->filled('fromDate')) {
            $toDateRules[] = 'after_or_equal:fromDate';
        }

        return [
            'fromDate' => ['nullable', 'date_format:Y-m-d'],
            'toDate' => $toDateRules,
            'pageNo' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
            'agentType' => ['nullable', Rule::enum(AgentType::class)],
            'regionCode' => ['nullable', 'string', 'max:50'],
            'agentOrderNo' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return string 当前路由绑定的控制器动作名称 */
    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

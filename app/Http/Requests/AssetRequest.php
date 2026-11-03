<?php

namespace App\Http\Requests;

use App\Enums\AssetAssessmentPeriodType;
use App\Enums\AssetBusinessType;
use App\Enums\AssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * 资产接口请求校验器
 * 负责按资产控制器动作分发规则，并校验资产汇总和流水参数
 */
class AssetRequest extends FormRequest
{
    /**
     * 判断当前请求是否允许进入参数校验
     *
     * 业务逻辑：资产流水接口认证由路由中间件处理。
     *
     * @return bool 始终返回 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 返回当前控制器动作的校验规则
     *
     * 业务逻辑：按资产汇总和流水动作分发规则，未知动作失败关闭。
     *
     * @return array<string, mixed> 当前动作的字段校验规则
     *
     * @throws LogicException 当前动作未配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'records' => $this->rulesForRecords(),
            default => throw new LogicException('AssetRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回资产字段的中文校验消息
     *
     * 业务逻辑：为资产流水查询参数提供稳定、可读的中文提示。
     *
     * @return array<string, string> 校验规则与中文消息的映射
     */
    public function messages(): array
    {
        return [
            'fromDate.required' => '发生日期起始日不能为空',
            'fromDate.date_format' => '发生日期起始日格式必须为 YYYY-MM-DD',
            'toDate.required' => '发生日期截止日不能为空',
            'toDate.date_format' => '发生日期截止日格式必须为 YYYY-MM-DD',
            'toDate.after_or_equal' => '发生日期截止日不能早于起始日',
            'pageNo.integer' => '页码必须是整数',
            'pageNo.min' => '页码必须从1开始',
            'pageSize.integer' => '每页数量必须是整数',
            'pageSize.min' => '每页数量至少为1',
            'pageSize.max' => '每页数量不能超过100',
            'businessType.enum' => '业务来源类型不正确',
            'assetType.enum' => '资产类型不正确',
            'assessmentPeriodType.enum' => '考核周期类型不正确',
        ];
    }

    /**
     * 返回资产字段的中文名称
     *
     * 业务逻辑：把第三方接口字段名转换为用户可理解的业务名称。
     *
     * @return array<string, string> 字段名与中文名称的映射
     */
    public function attributes(): array
    {
        return [
            'fromDate' => '发生日期起始日',
            'toDate' => '发生日期截止日',
            'pageNo' => '页码',
            'pageSize' => '每页数量',
            'businessType' => '业务来源类型',
            'assetType' => '资产类型',
            'assessmentPeriodType' => '考核周期类型',
        ];
    }

    /**
     * 返回资产流水查询规则
     *
     * @return array<string, mixed> 资产流水字段校验规则
     */
    private function rulesForRecords(): array
    {
        return [
            'fromDate' => ['required', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:fromDate'],
            'pageNo' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
            'businessType' => ['nullable', Rule::enum(AssetBusinessType::class)],
            'assetType' => ['nullable', Rule::enum(AssetType::class)],
            'assessmentPeriodType' => ['nullable', Rule::enum(AssetAssessmentPeriodType::class)],
        ];
    }

    /**
     * 获取当前路由绑定的控制器动作
     *
     * @return string 当前控制器动作名称
     */
    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

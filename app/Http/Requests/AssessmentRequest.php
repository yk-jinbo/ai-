<?php

namespace App\Http\Requests;

use App\Enums\AssetBusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * 考核接口请求校验器
 * 按控制器动作校验考核业务类型参数
 */
class AssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     * @throws LogicException 当前控制器动作未配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ((string) $this->route()?->getActionMethod()) {
            'getList' => [],
            'assessmentInfo' => $this->rulesForAssessmentInfo(),
            default => throw new LogicException('AssessmentRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    public function messages(): array
    {
        return [
            'businessType.required' => '业务类型不能为空',
            'businessType.enum' => '业务类型不正确',
            'asOfDate.date_format' => '统计日期格式必须为 YYYY-MM-DD',
            'orgId.required_if' => '分公司考核必须传入机构 ID',
            'orgId.integer' => '机构 ID 必须是整数',
            'merchantId.required_if' => '招商公司考核必须传入招商公司 ID',
            'merchantId.integer' => '招商公司 ID 必须是整数',
            'fromDate.date_format' => '开始日期格式必须为 YYYY-MM-DD',
            'toDate.date_format' => '结束日期格式必须为 YYYY-MM-DD',
            'toDate.after_or_equal' => '结束日期不能早于开始日期',
            'pageNo.integer' => '页码必须是整数',
            'pageNo.min' => '页码必须从 1 开始',
            'pageSize.integer' => '每页数量必须是整数',
            'pageSize.min' => '每页数量至少为 1',
            'pageSize.max' => '每页数量不能超过 100',
        ];
    }

    public function attributes(): array
    {
        return [
            'businessType' => '业务类型',
            'asOfDate' => '统计日期',
            'orgId' => '机构 ID',
            'merchantId' => '招商公司 ID',
            'fromDate' => '开始日期',
            'toDate' => '结束日期',
            'pageNo' => '页码',
            'pageSize' => '每页数量',
        ];
    }

    /** @return array<string, mixed> 考核信息查询字段校验规则 */
    private function rulesForAssessmentInfo(): array
    {
        $toDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($this->filled('fromDate')) {
            $toDateRules[] = 'after_or_equal:fromDate';
        }

        return [
            'businessType' => ['required', Rule::enum(AssetBusinessType::class)],
            'asOfDate' => ['nullable', 'date_format:Y-m-d'],
            'orgId' => ['required_if:businessType,BRANCH_COMPANY', 'nullable', 'integer'],
            'merchantId' => ['required_if:businessType,MERCHANT_COMPANY', 'nullable', 'integer'],
            'fromDate' => ['nullable', 'date_format:Y-m-d'],
            'toDate' => $toDateRules,
            'pageNo' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

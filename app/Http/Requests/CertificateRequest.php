<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * 数证中转请求校验器。
 */
class CertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'profile' => [],
            'list' => [],
            'listByType' => [
                'certType' => ['required', 'string'],
            ],
            'perList' => [],
            default => throw new LogicException('CertificateRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    public function messages(): array
    {
        return [
            'certType.required' => '证书类型不能为空',
            'certType.string' => '证书类型必须是字符串',
        ];
    }

    public function attributes(): array
    {
        return [
            'certType' => '证书类型',
        ];
    }

    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

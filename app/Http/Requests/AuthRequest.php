<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * 会员认证请求校验器。
 *
 * 一个认证请求覆盖认证控制器的全部动作，并按动作显式分发规则。
 */
class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'captcha' => $this->rulesForCaptcha(),
            'sendSms' => $this->rulesForSendSms(),
            'passwordLogin' => $this->rulesForPasswordLogin(),
            'smsLogin' => $this->rulesForSmsLogin(),
            'agreementPolicy' => $this->rulesForAgreementPolicy(),
            'agreementVersion' => $this->rulesForAgreementVersion(),
            'confirmLogin' => $this->rulesForConfirmLogin(),
            'logout' => $this->rulesForNoParams(),
            default => throw new LogicException('AuthRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    public function messages(): array
    {
        return [...$this->loginMessages(), ...$this->agreementMessages()];
    }

    /** @return array<string, string> 原有登录参数的中文提示 */
    private function loginMessages(): array
    {
        return [
            'mobile.required' => '手机号不能为空',
            'mobile.regex' => '手机号格式不正确',
            'code.required' => '验证码不能为空',
            'code.digits' => '验证码必须是6位数字',
            'account.required_without' => '账号不能为空',
            'account.string' => '账号必须是字符串',
            'account.max' => '账号长度不能超过64位',
            'account.regex' => '账号不能为空白字符',
            'username.required_without' => '账号不能为空',
            'username.string' => '账号必须是字符串',
            'username.max' => '账号长度不能超过64位',
            'username.regex' => '账号不能为空白字符',
            'password.required' => '密码不能为空',
            'password.max' => '密码长度不能超过64位',
            'password.regex' => '密码不能为空白字符',
            'captcha_id.required' => '滑块验证码标识不能为空',
            'captcha_id.string' => '滑块验证码标识格式不正确',
            'captcha_id.max' => '滑块验证码标识格式不正确',
            'offset_x.required' => '滑块横向位移不能为空',
            'offset_x.integer' => '滑块横向位移必须是整数',
            'offset_x.min' => '滑块横向位移不能小于0',
            'offset_x.max' => '滑块横向位移超出背景范围',
            'ticket.required' => '登录票据不能为空',
            'ticket.string' => '登录票据格式不正确',
            'member_type.required' => '登录身份不能为空',
            'member_type.string' => '登录身份必须为字符串',
            'member_type.in' => '登录身份不受支持',
        ];
    }

    /** @return array<string, string> 协议读取及同意信息的中文提示 */
    private function agreementMessages(): array
    {
        return [
            'scene.in' => '协议场景不受支持',
            'scene.string' => '协议场景必须为字符串',
            'version_id.required' => '协议版本ID不能为空',
            'version_id.string' => '协议版本ID必须为字符串',
            'version_id.regex' => '协议版本ID必须为正整数字符串',
            'agreement_consent.array' => '协议确认必须为包含同意状态和版本信息的对象',
            'agreement_consent.min' => '协议确认信息不能为空',
            'agreement_consent.accepted.required_with' => '请提供协议同意状态',
            'agreement_consent.accepted.boolean' => '协议同意状态必须为布尔值',
            'agreement_consent.policy_hash.required_if' => '请提供协议清单摘要',
            'agreement_consent.policy_hash.string' => '协议清单摘要必须为字符串',
            'agreement_consent.policy_hash.max' => '协议清单摘要长度不能超过256位',
            'agreement_consent.policy_hash.regex' => '协议清单摘要不能为空白字符',
            'agreement_consent.documents.required_if' => '请提供完整的协议版本集合',
            'agreement_consent.documents.array' => '协议版本集合必须为数组',
            'agreement_consent.documents.list' => '协议版本集合必须为列表',
            'agreement_consent.documents.min' => '协议版本集合不能为空',
            'agreement_consent.documents.*.array' => '每项协议版本必须包含协议编码和版本ID',
            'agreement_consent.documents.*.document_code.required' => '协议编码不能为空',
            'agreement_consent.documents.*.document_code.string' => '协议编码必须为字符串',
            'agreement_consent.documents.*.document_code.max' => '协议编码长度不能超过128位',
            'agreement_consent.documents.*.document_code.regex' => '协议编码不能为空白字符',
            'agreement_consent.documents.*.document_code.distinct' => '协议编码不能重复',
            'agreement_consent.documents.*.version_id.required' => '协议版本ID不能为空',
            'agreement_consent.documents.*.version_id.string' => '协议版本ID必须按字符串传递',
            'agreement_consent.documents.*.version_id.regex' => '协议版本ID必须为正整数字符串',
        ];
    }

    public function attributes(): array
    {
        return [
            'mobile' => '手机号',
            'code' => '验证码',
            'account' => '账号',
            'username' => '账号',
            'password' => '密码',
            'captcha_id' => '滑块验证码标识',
            'offset_x' => '滑块横向位移',
            'ticket' => '登录票据',
            'member_type' => '登录身份',
            'scene' => '协议场景',
            'version_id' => '协议版本ID',
            'agreement_consent' => '协议确认信息',
            'agreement_consent.accepted' => '协议同意状态',
            'agreement_consent.policy_hash' => '协议清单摘要',
            'agreement_consent.documents' => '协议版本集合',
            'agreement_consent.documents.*.document_code' => '协议编码',
            'agreement_consent.documents.*.version_id' => '协议版本ID',
        ];
    }

    private function rulesForCaptcha(): array
    {
        return [];
    }

    private function rulesForSendSms(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            ...$this->captchaRules(),
        ];
    }

    private function rulesForPasswordLogin(): array
    {
        return [
            'account' => ['required_without:username', 'nullable', 'string', 'max:64', 'regex:/\S/'],
            'username' => ['required_without:account', 'nullable', 'string', 'max:64', 'regex:/\S/'],
            'password' => ['required', 'string', 'max:64', 'regex:/\S/'],
            ...$this->captchaRules(),
        ];
    }

    private function rulesForSmsLogin(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'code' => ['required', 'digits:6'],
        ];
    }

    private function rulesForConfirmLogin(): array
    {
        return [
            'ticket' => ['required', 'string', 'max:512'],
            'member_type' => ['required', 'string', 'in:NORMAL,VIRTUAL,BRACELET'],
            // 是否强制同意由上游配置决定；本项目校验结构，不把 false 改为 true。
            'agreement_consent' => ['sometimes', 'array:accepted,policy_hash,documents', 'min:1'],
            'agreement_consent.accepted' => ['required_with:agreement_consent', 'boolean'],
            'agreement_consent.policy_hash' => ['required_if:agreement_consent.accepted,true', 'string', 'max:256', 'regex:/\S/'],
            'agreement_consent.documents' => ['required_if:agreement_consent.accepted,true', 'array', 'list', 'min:1'],
            'agreement_consent.documents.*' => ['array:document_code,version_id'],
            'agreement_consent.documents.*.document_code' => ['required', 'string', 'max:128', 'regex:/\S/', 'distinct'],
            'agreement_consent.documents.*.version_id' => ['required', 'string', 'regex:/^[1-9][0-9]*$/'],
        ];
    }

    private function rulesForAgreementPolicy(): array
    {
        return [
            'scene' => ['sometimes', 'string', 'in:LOGIN,REGISTER'],
            'member_type' => ['required', 'string', 'in:NORMAL,VIRTUAL,BRACELET'],
        ];
    }

    private function rulesForAgreementVersion(): array
    {
        return ['version_id' => ['required', 'string', 'regex:/^[1-9][0-9]*$/']];
    }

    private function rulesForNoParams(): array
    {
        return [];
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    private function captchaRules(): array
    {
        return [
            'captcha_id' => ['required', 'string', 'max:128'],
            'offset_x' => ['required', 'integer', 'min:0', 'max:280'],
        ];
    }

    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

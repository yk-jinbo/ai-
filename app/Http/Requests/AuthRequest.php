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
            'confirmLogin' => $this->rulesForConfirmLogin(),
            'logout' => $this->rulesForNoParams(),
            default => throw new LogicException('AuthRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    public function messages(): array
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
            'member_type.in' => '登录身份不受支持',
            'agreement_accepted.accepted' => '请先同意用户协议',
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
            'agreement_accepted' => '协议确认',
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
            'agreement_accepted' => ['accepted'],
        ];
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

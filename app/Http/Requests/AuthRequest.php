<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

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
            'login' => $this->rulesForLogin(),
            'me' => $this->rulesForMe(),
            'logout' => $this->rulesForLogout(),
            default => throw new LogicException('AuthRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    public function messages(): array
    {
        return [
            'username.required' => '用户名不能为空',
            'username.string' => '用户名必须是字符串',
            'username.max' => '用户名最多 64 个字符',
            'password.required' => '密码不能为空',
            'password.string' => '密码必须是字符串',
            'password.min' => '密码至少 1 个字符',
            'password.max' => '密码最多 255 个字符',
            'captcha_id.required' => '滑块验证码标识不能为空',
            'captcha_id.string' => '滑块验证码标识格式不正确',
            'captcha_id.size' => '滑块验证码标识格式不正确',
            'captcha_id.regex' => '滑块验证码标识格式不正确',
            'offset_x.required' => '滑块横向位移不能为空',
            'offset_x.integer' => '滑块横向位移必须是整数',
            'offset_x.min' => '滑块横向位移不能小于 0',
            'offset_x.max' => '滑块横向位移超出背景范围',
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => '用户名',
            'password' => '密码',
            'captcha_id' => '滑块验证码标识',
            'offset_x' => '滑块横向位移',
        ];
    }

    private function rulesForCaptcha(): array
    {
        return [];
    }

    private function rulesForLogin(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
            'captcha_id' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'offset_x' => ['required', 'integer', 'min:0', 'max:280'],
        ];
    }

    private function rulesForMe(): array
    {
        return [];
    }

    private function rulesForLogout(): array
    {
        return [];
    }

    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

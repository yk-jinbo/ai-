<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * 测试接口请求校验器。
 * 显式分发控制器动作规则，并限制可选测试消息的格式和长度。
 */
class TestRequest extends FormRequest
{
    private const MAX_MESSAGE_LENGTH = 100;

    /**
     * 允许匿名访问，便于直接在浏览器验证部署结果。
     *
     * @return bool 始终允许进入参数校验
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 按当前动作分发校验规则，未配置动作时拒绝继续处理。
     *
     * @return array<string, array<int, string>> 当前动作的校验规则
     *
     * @throws LogicException 控制器动作没有配置校验规则时抛出
     */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'index' => $this->rulesForIndex(),
            default => throw new LogicException('TestRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 提供可选消息的中文校验提示。
     *
     * @return array<string, string> 校验规则与中文消息的映射
     */
    public function messages(): array
    {
        return [
            'message.string' => '测试消息必须是字符串',
            'message.max' => '测试消息不能超过100个字符',
        ];
    }

    /**
     * 将请求字段映射为可读的中文名称。
     *
     * @return array<string, string> 请求字段的中文名称
     */
    public function attributes(): array
    {
        return ['message' => '测试消息'];
    }

    /**
     * 限制测试消息，避免公开接口接收无界输入。
     *
     * @return array<string, array<int, string>> 测试动作的参数规则
     */
    private function rulesForIndex(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:'.self::MAX_MESSAGE_LENGTH],
        ];
    }
}

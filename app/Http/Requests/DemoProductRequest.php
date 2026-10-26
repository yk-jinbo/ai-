<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class DemoProductRequest extends FormRequest
{
    /**
     * Demo 商品接口当前不要求登录权限。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 根据当前 Controller 动作分发对应的校验规则。
     */
    public function rules(): array
    {
        return match ($this->controllerAction()) {
            'index' => $this->rulesForIndex(),
            'search' => $this->rulesForSearch(),
            'show' => $this->rulesForShow(),
            'store' => $this->rulesForStore(),
            'update' => $this->rulesForUpdate(),
            'destroy' => $this->rulesForDestroy(),
            default => throw new LogicException('DemoProductRequest 未配置当前 Controller 动作的校验规则'),
        };
    }

    /**
     * 返回中文校验消息。
     */
    public function messages(): array
    {
        return [
            'keyword.string' => '关键词必须是字符串',
            'keyword.max' => '关键词不能超过100个字符',
            'min_price.numeric' => '最低价格必须是数字',
            'min_price.min' => '最低价格不能小于0',
            'max_price.numeric' => '最高价格必须是数字',
            'max_price.min' => '最高价格不能小于0',
            'max_price.gte' => '最高价格不能低于最低价格',
            'in_stock.boolean' => '库存筛选必须是布尔值',
            'sort_by.in' => '排序字段不合法',
            'sort_order.in' => '排序方向不合法',
            'per_page.integer' => '每页数量必须是整数',
            'per_page.min' => '每页数量至少为1',
            'per_page.max' => '每页数量不能超过100',
            'name.required' => '商品名称不能为空',
            'name.unique' => '商品名称已存在',
            'name.max' => '商品名称不能超过100个字符',
            'price.required' => '价格不能为空',
            'price.numeric' => '价格必须是数字',
            'price.min' => '价格不能小于0',
            'stock.required' => '库存不能为空',
            'stock.integer' => '库存必须是整数',
            'stock.min' => '库存不能小于0',
            'image.image' => '只能上传图片文件',
            'image.max' => '图片大小不能超过2MB',
            'status.boolean' => '状态必须是布尔值',
        ];
    }

    /**
     * 返回中文字段名称。
     */
    public function attributes(): array
    {
        return [
            'keyword' => '关键词',
            'min_price' => '最低价格',
            'max_price' => '最高价格',
            'in_stock' => '库存筛选',
            'sort_by' => '排序字段',
            'sort_order' => '排序方向',
            'per_page' => '每页数量',
            'name' => '商品名称',
            'description' => '商品描述',
            'price' => '价格',
            'stock' => '库存',
            'image' => '商品图片',
            'status' => '状态',
        ];
    }

    /**
     * 商品列表校验规则。
     */
    private function rulesForIndex(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 商品搜索校验规则。
     */
    private function rulesForSearch(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'in_stock' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'sort_by' => [
                'nullable',
                Rule::in(['id', 'name', 'price', 'stock', 'created_at', 'updated_at']),
            ],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 商品详情当前没有额外请求参数。
     */
    private function rulesForShow(): array
    {
        return [];
    }

    /**
     * 创建商品校验规则。
     */
    private function rulesForStore(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('demo_products', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'stock' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
            'status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 更新商品校验规则。
     */
    private function rulesForUpdate(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('demo_products', 'name')->ignore($this->route('id')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'max:2048'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * 删除商品当前没有额外请求参数。
     */
    private function rulesForDestroy(): array
    {
        return [];
    }

    /**
     * 获取当前路由绑定的 Controller 方法名。
     */
    private function controllerAction(): string
    {
        return (string) $this->route()?->getActionMethod();
    }
}

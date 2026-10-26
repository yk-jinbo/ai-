<?php

namespace App\Helpers;

use App\Enums\ApiCode;
use Illuminate\Http\JsonResponse;

class ResponseHelper
{
    /**
     * 组织返回内容
     *
     * @param  mixed  $data  返回数据
     * @param  string  $message  提示信息
     * @param  ApiCode|int  $code  业务状态码（0表示成功，非0表示失败）
     * @param  int  $httpStatus  HTTP response status code.
     */
    public static function response(
        mixed $data = null,
        string $message = 'success',
        ApiCode|int $code = ApiCode::SUCCESS,
        int $httpStatus = 200,
        int $encodingOptions = 0,
    ): JsonResponse {
        $code = self::codeValue($code);

        return new JsonResponse(compact('code', 'message', 'data'), $httpStatus, [], $encodingOptions);
    }

    /**
     * 成功响应（快捷方法）
     *
     * @param  mixed  $data  返回数据
     * @param  string  $message  提示信息
     * @param  int  $httpStatus  HTTP response status code.
     */
    public static function success(
        mixed $data = null,
        string $message = 'success',
        int $httpStatus = 200,
        int $encodingOptions = 0,
    ): JsonResponse {
        return self::response($data, $message, ApiCode::SUCCESS, $httpStatus, $encodingOptions);
    }

    /**
     * 失败响应（快捷方法）
     *
     * @param  string  $message  错误信息
     * @param  ApiCode|int  $code  错误码
     * @param  mixed  $data  附加数据
     * @param  int  $httpStatus  HTTP response status code.
     */
    public static function error(
        string $message = 'error',
        ApiCode|int $code = ApiCode::ERROR,
        mixed $data = null,
        int $httpStatus = 400
    ): JsonResponse {
        return self::response($data, $message, $code, $httpStatus);
    }

    /**
     * 条件判断抛出异常
     *
     * @param  bool  $condition  条件
     * @param  string  $message  错误信息
     * @param  ApiCode|int  $code  错误码
     * @param  callable|null  $callback  回调函数
     *
     * @throws \Exception
     */
    public static function errorIf(
        bool $condition,
        string $message = '',
        ApiCode|int $code = ApiCode::ERROR,
        $callback = null
    ): bool {
        if ($condition) {
            // 如果是个匿名函数，回调
            if ($callback instanceof \Closure) {
                $callback();
            }

            throw new \Exception($message, self::codeValue($code));
        }

        return true;
    }

    /**
     * 直接抛出异常
     *
     * @param  string  $message  错误信息
     * @param  ApiCode|int  $code  错误码
     *
     * @throws \Exception
     */
    public static function throwError(string $message = '', ApiCode|int $code = ApiCode::ERROR): bool
    {
        return self::errorIf(true, $message, $code);
    }

    /**
     * 获取异常详细信息
     *
     * @param  \Exception  $e  异常对象
     */
    public static function logInfo($e): array
    {
        $file = $e->getFile();
        $line = $e->getLine();
        $message = $e->getMessage();

        return compact('file', 'line', 'message');
    }

    private static function codeValue(ApiCode|int $code): int
    {
        return $code instanceof ApiCode ? $code->value : $code;
    }
}

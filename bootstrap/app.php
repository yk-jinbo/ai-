<?php

use App\Enums\ApiCode;
use App\Helpers\ResponseHelper;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAccountStatus;
use Exception as GlobalException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable as GlobalThrowable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'account.status' => EnsureAccountStatus::class,
            'permission' => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (GlobalThrowable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return ResponseHelper::response(
                    $exception->errors(),
                    $exception->validator->errors()->first(),
                    ApiCode::INVALID_PARAMS,
                    422,
                );
            }

            if ($exception instanceof AuthenticationException) {
                return ResponseHelper::response(null, '未认证或令牌无效', ApiCode::UNAUTHORIZED, 401);
            }

            if ($exception instanceof NotFoundHttpException) {
                return ResponseHelper::response(null, '资源不存在', ApiCode::NOT_FOUND, 404);
            }

            if ($exception instanceof QueryException) {
                $sqlState = (string) $exception->getCode();
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                if (in_array($sqlState, ['23000', '23503', '23505'], true)
                    || in_array($driverCode, [19, 1062, 1555, 2067], true)) {
                    return ResponseHelper::response(null, '资源冲突或不一致', ApiCode::CONFLICT, 409);
                }
            }

            $businessCodes = array_map(
                static fn (ApiCode $code): int => $code->value,
                ApiCode::cases(),
            );
            $isBusinessException = $exception::class === GlobalException::class
                && is_int($exception->getCode())
                && in_array($exception->getCode(), $businessCodes, true);
            $code = $isBusinessException ? $exception->getCode() : ApiCode::ERROR->value;
            $httpStatus = match ($code) {
                ApiCode::INVALID_PARAMS->value => 422,
                ApiCode::UNAUTHORIZED->value => 401,
                ApiCode::FORBIDDEN->value => 403,
                ApiCode::NOT_FOUND->value => 404,
                ApiCode::CONFLICT->value => 409,
                default => 400,
            };

            return ResponseHelper::response(
                null,
                $isBusinessException && $exception->getMessage() !== '' ? $exception->getMessage() : '服务异常',
                $code,
                $httpStatus,
            );
        });
    })->create();

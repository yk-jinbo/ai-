<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Enums\ExternalGateway;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * 数证上游 HTTP 客户端。
 *
 * 只负责上游路径、鉴权头和 {code,msg,traceId,data} 响应解析；HTTP 请求与失败日志由通用客户端完成。
 */
class HttpCertificateClient implements CertificateClient
{
    private const MEMBER_PROFILE_PATH = '/api-iam/api/v1/member/profile';

    private const MEMBER_LIST_PATH = '/api-iam/api/v1/member/certificate/list';

    private const PER_LIST_PATH = '/point-partner-api/api/v1/points/per-certificates';

    public function __construct(private ExternalHttpClient $http) {}

    /**
     * 携带当前令牌查询会员资料，复用身份网关、响应转换和失败日志。
     *
     * @param  string  $token  当前请求的 Bearer Token
     * @return CertificateProxyResult 会员资料或标准化失败结果
     */
    public function memberProfile(string $token): CertificateProxyResult
    {
        return $this->get(self::MEMBER_PROFILE_PATH, $token);
    }

    public function memberCertificates(string $token): CertificateProxyResult
    {
        return $this->get(self::MEMBER_LIST_PATH, $token);
    }

    public function memberCertificatesByType(string $certType, string $token): CertificateProxyResult
    {
        $path = self::MEMBER_LIST_PATH.'/'.rawurlencode($certType);

        return $this->get($path, $token);
    }

    public function perCertificates(int $memberId, string $token): CertificateProxyResult
    {
        return $this->get(self::PER_LIST_PATH, $token, ['memberId' => $memberId]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, string $token, array $query = []): CertificateProxyResult
    {
        try {
            $response = $this->http->get(
                $path,
                $query,
                isSuccessful: static function (Response $response): bool {
                    return trim((string) $response->json('code')) === 'OK';
                },
                options: [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                ],
                service: 'digital_certificate',
                gateway: ExternalGateway::IDENTITY,
            );
        } catch (Throwable $exception) {
            return new CertificateProxyResult(
                data: null,
                message: $exception->getMessage() !== '' ? $exception->getMessage() : '数证服务暂时不可用',
                code: ApiCode::ERROR->value,
                httpStatus: 503,
                traceId: null,
            );
        }

        return $this->parse($response);
    }

    private function parse(Response $response): CertificateProxyResult
    {
        $body = json_decode($response->body());
        if (! $body instanceof \stdClass) {
            return new CertificateProxyResult(
                data: null,
                message: '数证服务响应格式异常',
                code: ApiCode::ERROR->value,
                httpStatus: 502,
            );
        }

        $body = get_object_vars($body);
        $upstreamCode = is_scalar($body['code'] ?? null)
            ? trim((string) $body['code'])
            : null;
        $message = is_scalar($body['msg'] ?? null)
            ? trim((string) $body['msg'])
            : (is_scalar($body['message'] ?? null) ? trim((string) $body['message']) : '数证服务返回失败');
        $traceId = is_scalar($body['traceId'] ?? null)
            ? trim((string) $body['traceId'])
            : trim((string) ($response->header('X-Trace-Id') ?? $response->header('traceId') ?? ''));
        $traceId = $traceId === '' ? null : $traceId;
        $data = $body['data'] ?? null;
        $extra = array_diff_key(
            $body,
            array_flip(['code', 'msg', 'message', 'traceId', 'data']),
        );

        if ($response->successful() && $upstreamCode === 'OK') {
            return new CertificateProxyResult(
                data: $data,
                message: $message !== '' ? $message : 'success',
                code: ApiCode::SUCCESS->value,
                httpStatus: 200,
                traceId: $traceId,
                extra: $extra,
            );
        }

        [$code, $httpStatus] = $this->failureCode($upstreamCode, $response->status());

        return new CertificateProxyResult(
            data: $data,
            message: $message !== '' ? $message : '数证服务返回失败',
            code: $code,
            httpStatus: $httpStatus,
            traceId: $traceId,
            extra: $extra,
        );
    }

    /** @return array{int, int} */
    private function failureCode(?string $upstreamCode, int $upstreamStatus): array
    {
        return match ($upstreamCode) {
            'AUTH_FAIL' => [ApiCode::UNAUTHORIZED->value, 401],
            'PARAM_INVALID' => [ApiCode::INVALID_PARAMS->value, 422],
            'FORBIDDEN' => [ApiCode::FORBIDDEN->value, 403],
            'NOT_FOUND' => [ApiCode::NOT_FOUND->value, 404],
            'BIZ_CONFLICT' => [ApiCode::CONFLICT->value, 409],
            'SERVICE_UNAVAILABLE' => [ApiCode::ERROR->value, 503],
            'INTERNAL_ERROR' => [ApiCode::ERROR->value, 500],
            default => [ApiCode::ERROR->value, $this->fallbackStatus($upstreamStatus)],
        };
    }

    private function fallbackStatus(int $status): int
    {
        if ($status >= 400 && $status <= 599) {
            return $status;
        }

        return 502;
    }
}

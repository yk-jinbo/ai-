<?php

namespace App\Services;

use App\Enums\ApiCode;

/**
 * 数证中转业务服务。
 *
 * 转发数证查询，并通过会员资料接口取得 PER 查询使用的登录会员 ID。
 */
class CertificateService
{
    public function __construct(private CertificateClient $certificateClient) {}

    public function list(string $token): CertificateProxyResult
    {
        return $this->certificateClient->memberCertificates($token);
    }

    public function listByType(string $certType, string $token): CertificateProxyResult
    {
        return $this->certificateClient->memberCertificatesByType($certType, $token);
    }

    public function memberProfile(string $token): CertificateProxyResult
    {
        return $this->certificateClient->memberProfile($token);
    }

    /**
     * 先查询当前会员资料，再以 data.id 作为 memberId 查询 PER 权证。
     * 资料查询失败或 ID 无效时终止调用，不使用前端参数或本地缓存 ID 回退。
     *
     * @param  string  $token  当前请求的 Bearer Token
     * @return CertificateProxyResult PER 权证列表、资料查询错误或资料格式错误
     */
    public function perList(string $token): CertificateProxyResult
    {
        $profile = $this->certificateClient->memberProfile($token);
        if ($profile->code !== ApiCode::SUCCESS->value) {
            return $profile;
        }

        $id = $profile->data instanceof \stdClass ? ($profile->data->id ?? null) : null;
        $memberId = is_int($id) || is_string($id)
            ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($memberId === false) {
            return new CertificateProxyResult(
                data: null,
                message: '会员资料响应缺少有效会员ID',
                code: ApiCode::ERROR->value,
                httpStatus: 502,
                traceId: $profile->traceId,
                extra: $profile->extra,
            );
        }

        return $this->certificateClient->perCertificates($memberId, $token);
    }
}

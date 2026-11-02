<?php

namespace App\Services;

use App\Enums\ApiCode;

/**
 * 数证中转业务服务。
 *
 * 负责把登录身份和请求参数交给数证客户端，并执行 PER 主体一致性校验。
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

    /**
     * @param  array<string, mixed>  $memberInfo
     */
    public function perList(int $memberId, array $memberInfo, string $token): CertificateProxyResult
    {
        $currentMemberId = filter_var($memberInfo['member_id'] ?? null, FILTER_VALIDATE_INT);
        if ($currentMemberId === false || $currentMemberId === null || $currentMemberId <= 0 || $currentMemberId !== $memberId) {
            return new CertificateProxyResult(
                data: null,
                message: '请求会员与登录会员不一致',
                code: ApiCode::FORBIDDEN->value,
                httpStatus: 403,
                traceId: null,
            );
        }

        return $this->certificateClient->perCertificates($memberId, $token);
    }
}

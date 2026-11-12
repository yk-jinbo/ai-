<?php

namespace App\Services;

interface CertificateClient
{
    /**
     * 根据当前令牌查询会员资料，供 PER 查询使用响应中的 data.id。
     *
     * @param  string  $token  当前请求的 Bearer Token
     * @return CertificateProxyResult 会员资料或上游失败结果
     */
    public function memberProfile(string $token): CertificateProxyResult;

    /**
     * 查询当前登录会员的全部会员数证。
     */
    public function memberCertificates(string $token): CertificateProxyResult;

    /**
     * 按原始 certType 查询当前登录会员的会员数证。
     */
    public function memberCertificatesByType(string $certType, string $token): CertificateProxyResult;

    /**
     * 查询指定会员的 PER 权证。
     */
    public function perCertificates(int $memberId, string $token): CertificateProxyResult;
}

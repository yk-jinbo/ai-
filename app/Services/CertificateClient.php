<?php

namespace App\Services;

interface CertificateClient
{
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

<?php

namespace App\Http\Controllers\Api\Certificate;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMemberToken;
use App\Http\Requests\CertificateRequest;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Middleware(AuthenticateMemberToken::class)]
#[Prefix('point-partner-api/api/v1/points')]
class PerCertificateController extends Controller
{
    public function __construct(private CertificateService $certificateService) {}

    #[Get('/per-certificates', name: 'api.certificate.per.list')]
    public function perList(CertificateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $memberInfo = $request->attributes->get('member_auth_user', []);
        $memberInfo = is_array($memberInfo) ? $memberInfo : [];
        $result = $this->certificateService->perList(
            (int) $data['memberId'],
            $memberInfo,
            (string) $request->bearerToken(),
        );

        return ResponseHelper::response(
            $result->data,
            $result->message,
            $result->code,
            $result->httpStatus,
            extra: $result->metadata(),
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Certificate;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMemberToken;
use App\Http\Requests\CertificateRequest;
use App\Services\CertificateProxyResult;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Middleware(AuthenticateMemberToken::class)]
#[Prefix('api/v1/member')]
class MemberCertificateController extends Controller
{
    public function __construct(private CertificateService $certificateService) {}

    #[Get('/profile', name: 'api.certificate.member.profile')]
    public function profile(CertificateRequest $request): JsonResponse
    {
        return $this->respond($this->certificateService->memberProfile((string) $request->bearerToken()));
    }

    #[Get('/certificate/list', name: 'api.certificate.member.list')]
    public function list(CertificateRequest $request): JsonResponse
    {
        return $this->respond($this->certificateService->list((string) $request->bearerToken()));
    }

    #[Get('/certificate/list-by-type', name: 'api.certificate.member.list-by-type')]
    public function listByType(CertificateRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->respond(
            $this->certificateService->listByType($data['certType'], (string) $request->bearerToken()),
        );
    }

    private function respond(CertificateProxyResult $result): JsonResponse
    {
        return ResponseHelper::response(
            $result->data,
            $result->message,
            $result->code,
            $result->httpStatus,
            extra: $result->metadata(),
        );
    }
}

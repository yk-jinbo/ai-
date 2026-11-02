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
#[Prefix('api-iam/api/v1/member/certificate')]
class MemberCertificateController extends Controller
{
    public function __construct(private CertificateService $certificateService) {}

    #[Get('/list', name: 'api.certificate.member.list')]
    public function list(CertificateRequest $request): JsonResponse
    {
        return $this->respond($this->certificateService->list((string) $request->bearerToken()));
    }

    #[Get('/list-by-type', name: 'api.certificate.member.list-by-type')]
    public function listByType(CertificateRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->respond(
            $this->certificateService->listByType($data['certType'], (string) $request->bearerToken()),
        );
    }

    private function respond(\App\Services\CertificateProxyResult $result): JsonResponse
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

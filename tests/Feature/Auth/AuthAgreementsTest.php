<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 验证匿名协议读取、字段映射、确认参数校验和上游失败日志。
 * HTTP、日志和数据库均使用隔离替身，不访问真实服务。
 */
class AuthAgreementsTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $handler;

    /**
     * 配置身份网关和内存日志，禁止测试意外访问外网。
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.hash_key', 'agreement-tests-only-cache-key');
        config()->set('services.member_auth.base_url', 'https://identity.example.test');
        config()->set('logging.channels.external_http', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        Log::forgetChannel('external_http');
        $this->handler = Log::channel('external_http')->getLogger()->getHandlers()[0];
        Http::preventStrayRequests();
    }

    /** @return array<string, array{string}> 用户可选择的身份 */
    public static function memberTypes(): array
    {
        return ['normal' => ['NORMAL'], 'virtual' => ['VIRTUAL'], 'bracelet' => ['BRACELET']];
    }

    /**
     * 按所选身份查询协议，转换字段且不转发旧令牌，版本 ID 保持字符串。
     *
     * @param  string  $memberType  用户选择的身份
     */
    #[DataProvider('memberTypes')]
    public function test_policy_is_anonymous_and_normalizes_all_document_versions(string $memberType): void
    {
        $documents = [
            ['documentCode' => 'USER_AGREEMENT', 'documentKind' => 'USER_AGREEMENT', 'versionId' => '9007199254740993', 'versionNo' => 4, 'title' => '用户协议', 'contentHash' => 'body-hash', 'sortNo' => 1],
            ['documentCode' => 'PRIVACY_POLICY', 'documentKind' => 'PRIVACY_POLICY', 'versionId' => '102', 'versionNo' => 1, 'title' => '隐私政策', 'sortNo' => 2],
        ];
        Http::fake(['*' => Http::response(['code' => 'OK', 'msg' => 'ok', 'data' => [
            'scene' => 'LOGIN', 'memberType' => $memberType, 'policyHash' => 'current-policy', 'documents' => $documents,
        ]])]);

        $response = $this->withToken('expired-old-token')->getJson('/api/auth/agreements/policy?member_type='.$memberType);
        $response->assertStatus(200)->assertJsonStructure(['code', 'message', 'data' => ['scene', 'member_type', 'policy_hash', 'documents']]);
        $response->assertJsonPath('data.policy_hash', 'current-policy')->assertJsonPath('data.member_type', $memberType);
        $response->assertJsonPath('data.documents.0.version_id', '9007199254740993')->assertJsonPath('data.documents.1.version_id', '102');
        $response->assertJsonPath('data.documents.0.document_code', 'USER_AGREEMENT')->assertJsonPath('data.documents.0.content_hash', 'body-hash');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://identity.example.test/api-iam/api/v1/member/agreements/policy?scene=LOGIN&memberType='.$memberType
            && ! $request->hasHeader('Authorization'));
        $this->assertSame([], $this->handler->getRecords());
    }

    /**
     * 正文的块、文本和样式保持原值，前端通过查询参数指定版本。
     */
    public function test_version_preserves_content_and_forwards_the_query_parameter(): void
    {
        $content = ['blocks' => [
            ['type' => 'section', 'runs' => [['text' => '第一条', 'bold' => true]]],
            ['type' => 'paragraph', 'runs' => [['text' => '正文', 'italic' => true, 'underline' => true]]],
        ]];
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => [
            'documentCode' => 'USER_AGREEMENT', 'memberType' => 'NORMAL', 'versionId' => '9007199254740993',
            'versionNo' => 4, 'title' => '用户协议', 'contentHash' => 'body-hash', 'state' => 'PUBLISHED', 'content' => $content,
        ]])]);

        $response = $this->getJson('/api/auth/agreements/versions?version_id=9007199254740993');
        $response->assertStatus(200)->assertJsonStructure(['code', 'message', 'data' => ['version_id', 'content']]);
        $response->assertJson(['code' => 0])->assertJsonPath('data.content', $content)->assertJsonPath('data.version_id', '9007199254740993');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://identity.example.test/api-iam/api/v1/member/agreements/versions/9007199254740993');
    }

    /** @return array<string, array{string}> 非法协议查询参数 */
    public static function invalidQueries(): array
    {
        return [
            'missing identity' => ['/api/auth/agreements/policy'],
            'invalid identity' => ['/api/auth/agreements/policy?member_type=UNKNOWN'],
            'invalid scene' => ['/api/auth/agreements/policy?member_type=NORMAL&scene=OTHER'],
            'missing version' => ['/api/auth/agreements/versions'],
            'zero version' => ['/api/auth/agreements/versions?version_id=0'],
            'negative version' => ['/api/auth/agreements/versions?version_id=-1'],
            'nonnumeric version' => ['/api/auth/agreements/versions?version_id=abc'],
        ];
    }

    /**
     * 非法身份、场景和版本在本地校验，不发起上游请求。
     *
     * @param  string  $path  非法协议查询地址
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_do_not_reach_upstream(string $path): void
    {
        $this->getJson($path)->assertStatus(422)->assertJsonStructure(['code', 'message', 'data'])->assertJson(['code' => -1001]);
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>}> 不完整或不符合格式的同意信息 */
    public static function invalidConsents(): array
    {
        $document = ['document_code' => 'USER_AGREEMENT', 'version_id' => '101'];
        $consent = ['accepted' => true, 'policy_hash' => 'confirmed-policy', 'documents' => [$document]];

        return [
            'missing policy hash' => [['accepted' => true, 'documents' => [$document]]],
            'missing versions' => [['accepted' => true, 'policy_hash' => 'confirmed-policy']],
            'numeric version loses precision' => [array_replace($consent, ['documents' => [['document_code' => 'USER_AGREEMENT', 'version_id' => 101]]])],
            'duplicate documents' => [array_replace($consent, ['documents' => [$document, $document]])],
            'empty consent' => [[]],
            'nonboolean acceptance' => [array_replace($consent, ['accepted' => 'yes'])],
        ];
    }

    /**
     * 不完整同意对象不能被错误转换或发送到上游。
     *
     * @param  array<string, mixed>  $consent  格式错误的同意对象
     */
    #[DataProvider('invalidConsents')]
    public function test_invalid_consents_do_not_reach_upstream(array $consent): void
    {
        $this->postJson('/api/auth/login/confirm', [
            'ticket' => 'test-ticket', 'member_type' => 'NORMAL', 'agreement_consent' => $consent,
        ])->assertStatus(422)->assertJsonStructure(['code', 'message', 'data'])->assertJson(['code' => -1001]);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string, int, int}> 上游协议错误与本地错误契约 */
    public static function upstreamFailures(): array
    {
        return [
            'policy unavailable' => ['/api/auth/agreements/policy?member_type=NORMAL', 'AGREEMENT_UNAVAILABLE', 400, -1],
            'version unavailable' => ['/api/auth/agreements/versions?version_id=101', 'AGREEMENT_UNAVAILABLE', 400, -1],
            'invalid query' => ['/api/auth/agreements/policy?member_type=NORMAL', 'PARAM_INVALID', 422, -1001],
            'policy changed' => ['/api/auth/login/confirm', 'AGREEMENT_VERSION_CHANGED', 409, -1005],
            'versions mismatch' => ['/api/auth/login/confirm', 'AGREEMENT_SET_MISMATCH', 409, -1005],
            'not accepted' => ['/api/auth/login/confirm', 'AGREEMENT_NOT_ACCEPTED', 409, -1005],
        ];
    }

    /**
     * 上游业务失败使用统一响应并自动记录一次带追踪标识的日志，不重试协议确认。
     *
     * @param  string  $path  请求地址
     * @param  string  $code  上游错误码
     * @param  int  $status  本地 HTTP 状态码
     * @param  int  $apiCode  本地业务码
     */
    #[DataProvider('upstreamFailures')]
    public function test_upstream_business_failures_are_returned_and_logged(string $path, string $code, int $status, int $apiCode): void
    {
        Http::fake(['*' => Http::response(['code' => $code, 'msg' => '请重新获取协议', 'traceId' => 'agreement-failure-trace', 'data' => null])]);
        $response = str_contains($path, '/login/confirm')
            ? $this->postJson($path, ['ticket' => 'test-ticket', 'member_type' => 'NORMAL'])
            : $this->getJson($path);

        $response->assertStatus($status)->assertExactJson(['code' => $apiCode, 'message' => '请重新获取协议', 'data' => null]);
        $records = $this->handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('upstream_business', $records[0]->context['failure_type']);
        $this->assertSame($code, $records[0]->context['upstream_code']);
        $this->assertSame('agreement-failure-trace', $records[0]->context['upstream_trace_id']);
        Http::assertSentCount(1);
    }

    /**
     * 协议连接异常被标准化为服务不可用，失败日志只记录一次。
     */
    public function test_agreement_connection_failure_is_logged(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection timed out'));

        $this->getJson('/api/auth/agreements/policy?member_type=NORMAL')->assertStatus(400)
            ->assertExactJson(['code' => -1, 'message' => '认证服务暂时不可用', 'data' => null]);
        $records = $this->handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('connection', $records[0]->context['failure_type']);
    }
}

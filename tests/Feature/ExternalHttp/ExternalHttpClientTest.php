<?php

namespace Tests\Feature\ExternalHttp;

use App\Enums\ExternalGateway;
use App\Services\AuthTokenService;
use App\Services\BarterService;
use App\Services\ExternalAuthClient;
use App\Services\ExternalHttpClient;
use App\Services\RelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * 验证通用外部请求的传参、失败分类、日志脱敏以及认证客户端迁移后的契约。
 * 所有外部请求均使用 HTTP Fake，数据库使用测试环境的 SQLite 内存库。
 */
class ExternalHttpClientTest extends TestCase
{
    use RefreshDatabase;

    private ExternalHttpClient $client;

    private TestHandler $handler;

    /**
     * 使用内存日志和伪造上游，确保验证不写业务日志且不访问外网。
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('logging.channels.external_http', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        Log::forgetChannel('external_http');
        $this->handler = Log::channel('external_http')->getLogger()->getHandlers()[0];
        $this->client = $this->app->make(ExternalHttpClient::class);
        $this->app->instance('request', Request::create('/api/orders', 'POST', server: ['HTTP_X_REQUEST_ID' => 'test-request-123']));
        Http::preventStrayRequests();
    }

    /** 验证普通成功响应和业务成功响应均不写日志。 */
    public function test_successful_requests_do_not_write_logs(): void
    {
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => ['id' => 7]])]);

        $this->assertSame(200, $this->client->get('https://orders.example.test/list')->status());
        $response = $this->client->post('https://orders.example.test/create', ['amount' => 10], fn (Response $response): bool => $response->json('code') === 'OK');

        $this->assertSame(7, $response->json('data.id'));
        $this->assertSame([], $this->handler->getRecords());
    }

    /** 验证 GET 查询参数、POST JSON、PATCH 和鉴权头可通过通用层传递。 */
    public function test_request_methods_parameters_headers_and_timeouts_are_preserved(): void
    {
        Http::fake(function (ClientRequest $request, array $options) {
            $this->assertSame(6, $options['timeout']);
            $this->assertSame(2, $options['connect_timeout']);

            return Http::response(['success' => true]);
        });
        $options = ['headers' => ['Authorization' => 'Bearer outbound-secret'], 'timeout' => 6, 'connect_timeout' => 2];
        $this->client->get('https://orders.example.test/list', ['page' => 2], options: $options);
        $this->client->post('https://orders.example.test/create', ['amount' => 10], options: $options);
        $this->client->request('PATCH', 'https://orders.example.test/7', ['status' => 'closed'], options: $options);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request['page'] === 2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST' && $request['amount'] === 10);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH' && $request['status'] === 'closed');
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer outbound-secret') && $request->hasHeader('X-Request-Id', 'test-request-123'));
        $this->assertSame([], $this->handler->getRecords());
    }

    /** 验证团队统计使用关系汇总接口的 JSON 契约并返回上游 data。 */
    public function test_relation_team_statistics_uses_upstream_summary(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => ['memberCount' => 12]])]);

        $statistics = $this->app->make(RelationService::class)->getTeamStatistics(
            ['mobile' => '13812345678', 'member_type' => 'NORMAL'],
            'test-token',
        );

        $this->assertSame(['memberCount' => 12], $statistics);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://192.168.11.202:8082/api/v1/computing/query/relations/summary'
                && $request['tenantId'] === 'demo'
                && $request['mobile'] === '13812345678'
                && $request['memberType'] === 'NORMAL'
                && $request['token'] === 'test-token'
                && $request['asOfDate'] === date('Y-m-d', strtotime('-1 day'))
                && $request['relationType'] === 'DIRECT';
        });
    }

    /** 验证 barter 统计使用首页指标接口的 JSON 契约并返回上游 data。 */
    public function test_barter_statistics_uses_home_indicators(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        $upstreamData = ['tongbaoPrice' => '121.11', 'yesterdayTradeAmount' => '999999.999'];
        Http::fake(function (ClientRequest $request, array $options) use ($upstreamData) {
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);

            return Http::response(['code' => 'OK', 'data' => $upstreamData]);
        });

        $result = $this->app->make(BarterService::class)->getBarterStatistics([
            'tenantId' => 'tenant-7',
            'mobile' => '13812345678',
            'member_type' => 'NORMAL',
        ], 'test-inbound-token');

        $this->assertSame([
            'today_general_coupon' => '121.11',
            'yesterday_barter_amount' => '999999.999',
        ], $result);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://192.168.11.202:8082/api/v1/computing/query/home/indicators'
                && $request['mobile'] === '13812345678'
                && $request['memberType'] === 'NORMAL'
                && $request['token'] === 'test-inbound-token';
        });
    }

    /** 验证 barter 控制器从当前 Bearer token 用户获取上游身份参数。 */
    public function test_barter_statistics_uses_current_authenticated_user(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        $this->mock(AuthTokenService::class)
            ->shouldReceive('currentUser')
            ->once()
            ->andReturn([
                'tenantId' => 'tenant-9',
                'mobile' => '13898765432',
                'member_type' => 'PREMIUM',
            ]);
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => [
            'tongbaoPrice' => '50.00',
            'yesterdayTradeAmount' => '80.00',
        ]])]);

        $this->withToken('test-bearer-token')->getJson('/api/barter/barter-statistics')
            ->assertOk()
            ->assertJsonPath('data.today_general_coupon', '50.00');

        Http::assertSent(fn (ClientRequest $request): bool => $request['mobile'] === '13898765432'
            && $request['memberType'] === 'PREMIUM'
            && $request['token'] === 'test-bearer-token');
    }

    /** 验证首页指标接口兼容 result 包装和指标字段直接位于顶层的响应。 */
    public function test_barter_statistics_accepts_alternate_upstream_response_shapes(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        Http::fakeSequence()
            ->push(['code' => 'OK', 'result' => ['tongbaoPrice' => '10.00', 'yesterdayTradeAmount' => '20.00']])
            ->push(['code' => 'OK', 'tongbaoPrice' => '30.00', 'yesterdayTradeAmount' => '40.00']);
        $service = $this->app->make(BarterService::class);
        $user = ['mobile' => '13812345678', 'member_type' => 'NORMAL'];

        $result = $service->getBarterStatistics($user, 'test-token');
        $this->assertSame('10.00', $result['today_general_coupon']);

        $result = $service->getBarterStatistics($user, 'test-token');
        $this->assertSame('30.00', $result['today_general_coupon']);
    }

    /** 验证团队列表将本地分页参数传给关系详情接口并返回上游 data。 */
    public function test_relation_team_list_uses_upstream_details(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        $upstreamData = ['items' => [['member' => ['mobile' => '13800000001', 'memberName' => 'Team']]], 'total' => 17];
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => $upstreamData])]);

        $result = $this->app->make(RelationService::class)->getTeamList(1, 10, [
            'mobile' => '13812345678', 'member_type' => 'PREMIUM',
        ], 'test-token');

        $this->assertSame(17, $result['total']);
        $this->assertSame('*******0001', $result['data'][0]['mobile']);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://192.168.11.202:8082/api/v1/computing/query/relations/details'
                && $request['tenantId'] === 'demo'
                && $request['mobile'] === '13812345678'
                && $request['memberType'] === 'PREMIUM'
                && $request['token'] === 'test-token'
                && $request['asOfDate'] === date('Y-m-d', strtotime('-1 day'))
                && $request['relationType'] === 'NETWORK'
                && $request['pageNo'] === 1
                && $request['pageSize'] === 10;
        });
    }

    /** 验证兑换流水列表将日期映射到关系网络流水接口。 */
    public function test_relation_exchange_statistics_list_uses_network_flows(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        $upstreamData = ['total' => 23, 'items' => [
            ['bizDate' => '2026-08-03', 'exchangeAmount' => '120.00', 'changeFromPreviousDay' => '999.00'],
            ['bizDate' => '2026-08-01', 'exchangeAmount' => '100.00'],
            ['bizDate' => '2026-08-02', 'exchangeAmount' => '80.00'],
        ]];
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => $upstreamData])]);

        $result = $this->app->make(RelationService::class)->getExchangeStatisticsList(
            '2026-08-01', '2026-08-03', 2, 5,
            ['mobile' => '13812345678', 'member_type' => 'NORMAL'], 'test-token',
        );

        $this->assertSame(23, $result['total']);
        $this->assertSame(2, $result['current_page']);
        $this->assertSame(5, $result['pagesize']);
        $this->assertSame([
            [
                'date' => '2026-08-01',
                'exchange_amount' => '100.00',
                'difference_from_previous_day' => '0.00',
                'difference_previous_day_type' => true,
            ],
            [
                'date' => '2026-08-02',
                'exchange_amount' => '80.00',
                'difference_from_previous_day' => '20.00',
                'difference_previous_day_type' => false,
            ],
            [
                'date' => '2026-08-03',
                'exchange_amount' => '120.00',
                'difference_from_previous_day' => '40.00',
                'difference_previous_day_type' => true,
            ],
        ], $result['data']);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://192.168.11.202:8082/api/v1/computing/query/relations/network/flows'
                && $request['tenantId'] === 'demo'
                && $request['mobile'] === '13812345678'
                && $request['memberType'] === 'NORMAL'
                && $request['token'] === 'test-token'
                && $request['asOfDate'] === '2026-08-03'
                && $request['fromDate'] === '2026-08-01'
                && $request['toDate'] === '2026-08-03'
                && $request['pageNo'] === 2
                && $request['pageSize'] === 5;
        });
    }

    /** 验证关系流水接口返回 0004 时标记无兑换权限。 */
    public function test_relation_exchange_statistics_marks_permission_false_for_code_0004(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        Http::fake(['*' => Http::response(['code' => '0004', 'message' => '无兑换权限'])]);

        $result = $this->app->make(RelationService::class)->getExchangeStatistics(
            ['mobile' => '13812345678', 'member_type' => 'NORMAL'],
            'test-token',
        );

        $this->assertSame([
            'total_exchange_amount' => '0.00',
            'today_exchange_amount' => '0.00',
            'has_exchange_permission' => false,
        ], $result);
    }

    /** 验证粉丝列表从 fan_name 生成 fan_name_abbr，避免读取错误字段导致接口异常。 */
    public function test_relation_fans_list_adds_abbreviation_from_fan_name(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => [
            'total' => 19,
            'items' => [[
                'member' => ['mobile' => '13800000001', 'memberName' => '张三', 'memberLevel' => 'VIP'],
                'userDailyExchangeAmount' => '20.00',
                'directMemberCount' => 3,
            ]],
        ]])]);

        $result = $this->app->make(RelationService::class)->getFansList(1, 10, [
            'mobile' => '13812345678', 'member_type' => 'NORMAL',
        ], 'test-token');

        $this->assertSame('张三', $result['data'][0]['fan_name']);
        $this->assertSame('张', $result['data'][0]['fan_name_abbr']);
        $this->assertSame('*******0001', $result['data'][0]['mobile']);
        $this->assertSame(19, $result['total']);
    }

    /** 验证关系列表返回的手机号统一隐藏中间四位。 */
    public function test_relation_lists_mask_mobile_fields(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        Http::fakeSequence()
            ->push(['code' => 'OK', 'data' => ['items' => [[
                'member' => ['mobile' => '13812345678', 'memberName' => '团队成员'],
            ]]]])
            ->push(['code' => 'OK', 'data' => ['items' => [[
                'bizDate' => '2026-08-01', 'exchangeAmount' => '10.00', 'mobile' => '13912345678',
            ]]]]);
        $service = $this->app->make(RelationService::class);
        $user = ['mobile' => '15012345678', 'member_type' => 'NORMAL'];

        $team = $service->getTeamList(1, 10, $user, 'test-token');
        $flows = $service->getExchangeStatisticsList('2026-08-01', '2026-08-01', 1, 10, $user, 'test-token');

        $this->assertSame('*******5678', $team['data'][0]['mobile']);
        $this->assertSame('*******5678', $flows['data'][0]['mobile']);
    }

    /** 验证关系接口把登录 token 去掉 Bearer 前缀后作为 JSON 字段传给上游。 */
    public function test_relation_controller_forwards_bearer_token_and_current_user(): void
    {
        config()->set('services.external_http.gateways.default', 'http://192.168.11.202:8082');
        $this->mock(AuthTokenService::class)
            ->shouldReceive('currentUser')
            ->once()
            ->andReturn(['mobile' => '13812345678', 'member_type' => 'NORMAL']);
        Http::fake(['*' => Http::response(['code' => 'OK', 'data' => [
            'items' => [['member' => ['mobile' => '13800000001', 'memberName' => '测试']]],
        ]])]);

        $this->withToken('relation-test-token')->getJson('/api/relation/team-list?page=1&pagesize=10')->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request['tenantId'] === 'demo'
            && $request['mobile'] === '13812345678'
            && $request['memberType'] === 'NORMAL'
            && $request['token'] === 'relation-test-token');
    }

    /** 验证 HTTP 错误只记录一次，保留上游状态、业务码和追踪标识。 */
    public function test_http_error_is_logged_once_with_diagnostic_context(): void
    {
        Http::fake(['*' => Http::response(['code' => 'UNAVAILABLE', 'message' => 'upstream unavailable'], 503, ['X-Trace-Id' => 'trace-503'])]);
        $response = $this->client->get('https://orders.example.test/list', ['page' => 2], service: 'orders');
        $context = $this->failure('http');

        $this->assertSame(503, $response->status());
        $this->assertSame(503, $context['http_status']);
        $this->assertSame('UNAVAILABLE', $context['upstream_code']);
        $this->assertSame('upstream unavailable', $context['upstream_message']);
        $this->assertSame('trace-503', $context['upstream_trace_id']);
        $this->assertSame('orders', $context['service']);
        $this->assertSame('test-request-123', $context['request_id']);
        $this->assertSame('https://orders.example.test/list?page=2', $context['request_url']);
        $this->assertSame('api/orders', $context['local_request']['path']);
        $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
    }

    /** 验证不同上游的成功规则可独立配置，HTTP 200 的业务失败也能记录。 */
    public function test_custom_business_failure_is_logged(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'order rejected'])]);
        $response = $this->client->post('https://orders.example.test/create', isSuccessful: fn (Response $response): bool => $response->json('success') === true);

        $this->assertSame(200, $response->status());
        $this->assertSame('order rejected', $this->failure('upstream_business')['upstream_message']);
    }

    /** 验证 HTML/非法 JSON 响应也会产生可检索的格式错误日志，不记录原始正文。 */
    public function test_invalid_response_is_logged_without_raw_body(): void
    {
        $html = '<html>gateway error with private content</html>';
        Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        $this->client->post('https://orders.example.test/create', isSuccessful: fn (Response $response): bool => $response->json('code') === 'OK');
        $context = $this->failure('invalid_response');

        $this->assertSame(strlen($html), $context['response_summary']['body_bytes']);
        $this->assertSame('text/html', $context['response_summary']['content_type']);
        $this->assertStringNotContainsString('private content', json_encode($context));
    }

    /** 验证连接异常只记录一次且原异常继续交给业务层处理。 */
    public function test_connection_error_is_logged_and_rethrown(): void
    {
        $exception = new ConnectionException('connection timed out');
        Http::fake(fn () => throw $exception);
        try {
            $this->client->get('https://orders.example.test/list');
            $this->fail('连接异常必须继续抛出');
        } catch (ConnectionException $caught) {
            $this->assertSame($exception, $caught);
        }

        $context = $this->failure('connection');
        $this->assertNull($context['http_status']);
        $this->assertSame(ConnectionException::class, $context['exception']['class']);
    }

    /** 验证业务成功判断自身抛异常时也能记录响应和源码位置，且不会重复记录。 */
    public function test_exception_in_success_predicate_is_logged_and_rethrown(): void
    {
        Http::fake(['*' => Http::response(['code' => 'OK'])]);
        try {
            $this->client->get('https://orders.example.test/list', isSuccessful: fn () => throw new RuntimeException('invalid upstream schema'));
            $this->fail('响应判断异常必须继续抛出');
        } catch (RuntimeException $exception) {
            $this->assertSame('invalid upstream schema', $exception->getMessage());
        }

        $this->assertSame(200, $this->failure('exception')['http_status']);
    }

    /** 验证配置错误无需发出请求也能定位到具体服务。 */
    public function test_invalid_url_is_logged_before_sending(): void
    {
        try {
            $this->client->get('', service: 'orders');
            $this->fail('缺少 URL 必须抛出配置异常');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('URL', $exception->getMessage());
        }

        $this->assertSame('orders', $this->failure('configuration')['service']);
        Http::assertNothingSent();
    }

    /** 验证 URL、请求参数、响应数据和错误消息中出现的凭证均被隐藏。 */
    public function test_credentials_are_redacted_while_business_code_remains_readable(): void
    {
        $data = ['password' => 'private-password', 'code' => '123456', 'ticket' => 'private-ticket', 'mobile' => '13812345678', 'address' => 'private-address'];
        Http::fake(['*' => Http::response([
            'code' => 'SMS_INVALID',
            'msg' => 'password private-password, sms 123456, ticket private-ticket, Bearer private-bearer',
            'traceId' => 'trace-private-test',
            'data' => ['accessToken' => 'private-access', 'refreshToken' => 'private-refresh'],
        ])]);
        $this->client->post(
            'https://url-user:url-password@orders.example.test/login?token=private-query',
            $data,
            fn (Response $response): bool => $response->json('code') === 'OK',
            ['headers' => ['Authorization' => 'Bearer private-bearer', 'Cookie' => 'session=private-cookie']],
        );
        $context = $this->failure('upstream_business');
        $encoded = json_encode($context);
        foreach (['private-password', '123456', 'private-ticket', '13812345678', 'private-address', 'private-bearer', 'private-access', 'private-refresh', 'private-query', 'url-user', 'url-password', 'private-cookie'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $this->assertSame('SMS_INVALID', $context['upstream_code']);
        $this->assertSame('138****5678', $context['request_summary']['safe_fields']['mobile']);
    }

    /** 验证自动生成的请求标识会在同一个本地请求中复用并传递上游。 */
    public function test_generated_request_id_is_reused_and_invalid_header_is_replaced(): void
    {
        $this->app['request']->headers->set('X-Request-Id', 'invalid request id');
        Http::fake(['*' => Http::response([], 502)]);
        $this->client->get('https://orders.example.test/list');
        $this->client->get('https://assets.example.test/list');
        $records = $this->handler->getRecords();
        $this->assertCount(2, $records);
        $requestId = $records[0]->context['request_id'];
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $requestId);
        $this->assertSame($requestId, $records[1]->context['request_id']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Request-Id', $requestId));
    }

    /** 验证认证业务继续使用原有参数、响应 DTO 和成功规则，共用通用客户端。 */
    public function test_authentication_requests_preserve_their_contract(): void
    {
        config()->set('services.member_auth.base_url', 'https://auth.example.test');
        Http::fake(['*' => Http::response(['code' => ' OK ', 'msg' => '成功', 'traceId' => 'auth-trace', 'data' => ['ticket' => 'login-ticket']])]);
        $auth = $this->app->make(ExternalAuthClient::class);

        $this->assertTrue($auth->sendSms('13812345678')->success);
        $this->assertTrue($auth->preparePasswordLogin('member', 'password')->success);
        $this->assertTrue($auth->prepareSmsLogin('13812345678', '123456')->success);
        $result = $auth->confirmLogin('login-ticket', 'NORMAL', [
            'accepted' => true,
            'policy_hash' => 'confirmed-policy',
            'documents' => [['document_code' => 'NORMAL_USER_AGREEMENT', 'version_id' => '101']],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('OK', $result->code);
        $this->assertSame('auth-trace', $result->traceId);
        $this->assertSame(['ticket' => 'login-ticket'], $result->data);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), 'sms/send') && $request['scene'] === 'LOGIN');
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), 'member/login/confirm') && $request['memberType'] === 'NORMAL' && $request['agreementConsent']['accepted'] === true && ! isset($request['agreementAccepted']));
        $this->assertSame([], $this->handler->getRecords());
    }

    /** 验证认证层会把连接异常转换为原来的错误结果，日志仍只写一次。 */
    public function test_authentication_converts_connection_error_without_duplicate_logs(): void
    {
        config()->set('services.member_auth.base_url', 'https://auth.example.test');
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $result = $this->app->make(ExternalAuthClient::class)->sendSms('13812345678');

        $this->assertFalse($result->success);
        $this->assertSame('INTERNAL_ERROR', $result->code);
        $this->assertSame('认证服务暂时不可用', $result->message);
        $this->assertSame('member_auth', $this->failure('connection')['service']);
    }

    /** 验证缺少认证地址的结果保持兼容，并由通用层记录配置错误。 */
    public function test_authentication_preserves_missing_configuration_result(): void
    {
        config()->set('services.member_auth.base_url', '');
        $result = $this->app->make(ExternalAuthClient::class)->sendSms('13812345678');

        $this->assertFalse($result->success);
        $this->assertSame('INTERNAL_ERROR', $result->code);
        $this->assertSame('认证服务未配置', $result->message);
        $this->assertSame('member_auth', $this->failure('configuration')['service']);
        Http::assertNothingSent();
    }

    /** 验证旧环境变量继续供普通业务使用，而所有登录动作和数证已切到新网关。 */
    public function test_legacy_gateway_is_preserved_while_login_and_certificate_use_identity_gateway(): void
    {
        config()->set('services', $this->gatewayConfig([
            'MEMBER_AUTH_BASE_URL' => 'https://original.example.test',
            'EXTERNAL_HTTP_BASE_URL' => null,
            'IDENTITY_GATEWAY_BASE_URL' => null,
        ]));
        Http::fake(['*' => Http::response(['code' => 'OK'])]);
        $auth = $this->app->make(ExternalAuthClient::class);
        $this->assertTrue($auth->sendSms('13812345678')->success);
        $this->assertTrue($auth->preparePasswordLogin('member', 'password')->success);
        $this->assertTrue($auth->prepareSmsLogin('13812345678', '123456')->success);
        $this->assertTrue($auth->confirmLogin('login-ticket', 'NORMAL', ['accepted' => true])->success);
        $this->assertTrue($auth->agreementPolicy('NORMAL', 'LOGIN')->success);
        $this->assertTrue($auth->agreementVersion('101')->success);
        $this->client->get('/api/orders', service: 'orders');
        $this->client->get('/api/certificate-example', service: 'digital_certificate', gateway: ExternalGateway::IDENTITY);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://original.example.test/api/orders');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://101.200.147.55:18080/api/certificate-example');
        foreach (['sms/send', 'member/login/prepare', 'member/login/sms/prepare', 'member/login/confirm'] as $endpoint) {
            Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://101.200.147.55:18080/api-iam/api/v1/'.$endpoint);
        }
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->url() === 'http://101.200.147.55:18080/api-iam/api/v1/member/agreements/policy?scene=LOGIN&memberType=NORMAL');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET' && $request->url() === 'http://101.200.147.55:18080/api-iam/api/v1/member/agreements/versions/101');
        Http::assertSentCount(8);
        $this->assertSame([], $this->handler->getRecords());
    }

    /** 验证显式配置优先、相对路径拼接正常、分流仍保持请求参数和鉴权头。 */
    public function test_gateway_overrides_are_used_for_relative_paths_and_authentication(): void
    {
        config()->set('services', $this->gatewayConfig([
            'MEMBER_AUTH_BASE_URL' => 'https://legacy.example.test',
            'EXTERNAL_HTTP_BASE_URL' => 'https://business.example.test/base/',
            'IDENTITY_GATEWAY_BASE_URL' => 'https://identity.example.test/',
        ]));
        Http::fake(['*' => Http::response(['code' => 'OK'])]);
        $options = ['headers' => ['Authorization' => 'Bearer certificate-token']];
        $this->client->get('api/orders', ['page' => 2], service: 'orders');
        $this->client->post('/api/certificate-example', ['memberId' => 9], options: $options, service: 'digital_certificate', gateway: ExternalGateway::IDENTITY);
        $this->client->request('PATCH', '/api/certificate-example/9', ['active' => true], gateway: ExternalGateway::IDENTITY);
        $this->app->make(ExternalAuthClient::class)->sendSms('13812345678');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://business.example.test/base/api/orders?page=2');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://identity.example.test/api/certificate-example' && $request['memberId'] === 9 && $request->hasHeader('Authorization', 'Bearer certificate-token'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH' && $request->url() === 'https://identity.example.test/api/certificate-example/9');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://identity.example.test/api-iam/api/v1/sms/send');
        Http::assertSentCount(4);
    }

    /** 验证旧完整 URL 不被网关配置改写，保留之前调用通用客户端的兼容性。 */
    public function test_existing_absolute_urls_are_not_rewritten_by_gateway_selection(): void
    {
        config()->set('services.external_http.gateways.default', 'https://business.example.test');
        config()->set('services.external_http.gateways.identity', 'https://identity.example.test');
        Http::fake(['*' => Http::response([])]);
        $this->client->get('https://legacy.example.test/api/orders');
        $this->client->post('https://legacy.example.test/api/explicit', gateway: ExternalGateway::IDENTITY);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://legacy.example.test/api/orders');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://legacy.example.test/api/explicit');
        Http::assertSentCount(2);
    }

    /** 验证缺少原业务网关时不会误发至身份网关，错误日志包含所选网关。 */
    public function test_missing_default_gateway_does_not_fall_back_to_identity_gateway(): void
    {
        config()->set('services', $this->gatewayConfig([
            'MEMBER_AUTH_BASE_URL' => 'https://legacy.example.test',
            'EXTERNAL_HTTP_BASE_URL' => '',
            'IDENTITY_GATEWAY_BASE_URL' => 'https://identity.example.test',
        ]));
        try {
            $this->client->get('/api/orders', service: 'orders');
            $this->fail('空网关配置必须报错');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('原业务网关', $exception->getMessage());
        }

        $context = $this->failure('configuration');
        $this->assertSame('default', $context['gateway']);
        $this->assertSame('/api/orders', $context['endpoint']);
        Http::assertNothingSent();
    }

    /** 验证身份网关失败时日志记录所选网关和拼接后的真实请求 URL。 */
    public function test_identity_gateway_failure_logs_resolved_target(): void
    {
        config()->set('services.external_http.gateways.identity', 'https://identity.example.test');
        Http::fake(['*' => Http::response(['code' => 'UNAVAILABLE'], 503)]);
        $this->client->get('/api/certificate-example', service: 'digital_certificate', gateway: ExternalGateway::IDENTITY);
        $context = $this->failure('http');

        $this->assertSame('identity', $context['gateway']);
        $this->assertSame('digital_certificate', $context['service']);
        $this->assertSame('https://identity.example.test/api/certificate-example', $context['request_url']);
    }

    /** @param array<string, string|null> $overrides @return array<string, mixed> */
    private function gatewayConfig(array $overrides): array
    {
        $repository = Env::getRepository();
        $original = [];
        try {
            foreach ($overrides as $name => $value) {
                $original[$name] = $repository->get($name);
                $repository->clear($name);
                if ($value !== null) {
                    $repository->set($name, $value);
                }
            }

            return require config_path('services.php');
        } finally {
            foreach ($original as $name => $value) {
                $repository->clear($name);
                if ($value !== null) {
                    $repository->set($name, $value);
                }
            }
        }
    }

    /** @return array<string, mixed> 唯一失败日志的上下文 */
    private function failure(string $type): array
    {
        $records = $this->handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('external_http_failure', $records[0]->message);
        $this->assertSame($type, $records[0]->context['failure_type']);

        return $records[0]->context;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Codeception\Module;

use Codeception\Lib\ModuleContainer;
use Codeception\Test\Unit;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use JasonBenett\CodeceptionModuleWiremock\Exception\RequestVerificationException;
use JasonBenett\CodeceptionModuleWiremock\Exception\WiremockException;
use JasonBenett\CodeceptionModuleWiremock\Module\Wiremock;
use ReflectionClass;

final class WiremockTest extends Unit
{
    protected ?Wiremock $module = null;
    protected MockHandler $mockHandler;
    protected Client $client;
    protected HttpFactory $httpFactory;

    protected function _setUp(): void
    {
        // Mock HTTP client with PSR-18
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->client = new Client(['handler' => $handlerStack]);

        // PSR-17 factories (Guzzle's HttpFactory implements both RequestFactory and StreamFactory)
        $this->httpFactory = new HttpFactory();

        $config = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'httpClient' => $this->client,
            'requestFactory' => $this->httpFactory,
            'streamFactory' => $this->httpFactory,
        ];

        $container = $this->createMock(ModuleContainer::class);
        $this->module = new Wiremock($container);
        $this->module->_setConfig($config);

        // Set baseUrl via reflection (needed for initialization without health check)
        $reflection = new ReflectionClass($this->module);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $baseUrlProperty->setValue($this->module, 'http://127.0.0.1:8080/__admin');

        // Set PSR clients via reflection
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->module, $this->client);

        $requestFactoryProperty = $reflection->getProperty('requestFactory');
        $requestFactoryProperty->setAccessible(true);
        $requestFactoryProperty->setValue($this->module, $this->httpFactory);

        $streamFactoryProperty = $reflection->getProperty('streamFactory');
        $streamFactoryProperty->setAccessible(true);
        $streamFactoryProperty->setValue($this->module, $this->httpFactory);
    }

    public function testHaveHttpStubForCreatesGetStub(): void
    {
        // Mock response from WireMock
        $this->mockHandler->append(
            new Response(201, [], json_encode([
                'id' => 'test-stub-id-123',
                'request' => ['method' => 'GET', 'url' => '/api/test'],
                'response' => ['status' => 200, 'body' => 'test body']
            ]))
        );

        $stubId = $this->module->haveHttpStubFor('GET', '/api/test', 200, 'test body');

        $this->assertSame('test-stub-id-123', $stubId);
    }

    public function testHaveHttpStubForWithJsonBody(): void
    {
        $this->mockHandler->append(
            new Response(201, [], json_encode([
                'id' => 'test-stub-json-456',
                'request' => ['method' => 'GET', 'url' => '/api/users/1'],
                'response' => ['status' => 200, 'jsonBody' => ['id' => 1, 'name' => 'John']]
            ]))
        );

        $stubId = $this->module->haveHttpStubFor('GET', '/api/users/1', 200, ['id' => 1, 'name' => 'John']);

        $this->assertSame('test-stub-json-456', $stubId);
    }

    public function testHaveHttpStubForWithMethod(): void
    {
        $this->mockHandler->append(
            new Response(201, [], json_encode([
                'id' => 'test-stub-post-789',
                'request' => ['method' => 'POST', 'url' => '/api/users'],
                'response' => ['status' => 201]
            ]))
        );

        $stubId = $this->module->haveHttpStubFor('POST', '/api/users', 201, ['success' => true]);

        $this->assertSame('test-stub-post-789', $stubId);
    }

    public function testHaveHttpStubForThrowsWhenNoIdReturned(): void
    {
        $this->mockHandler->append(
            new Response(201, [], json_encode(['error' => 'something went wrong']))
        );

        $this->expectException(WiremockException::class);
        $this->expectExceptionMessage('Failed to create stub mapping: no ID returned');

        $this->module->haveHttpStubFor('GET', '/api/test');
    }

    public function testSeeHttpRequestVerifiesRequest(): void
    {
        // Mock count response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 1]))
        );

        $this->module->seeHttpRequest('GET', '/api/test');

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testSeeHttpRequestThrowsWhenNotFound(): void
    {
        // Mock count response showing 0 matches
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 0]))
        );

        // Mock near-misses response (will fail but that's ok)
        $this->mockHandler->append(
            new Response(404, [], json_encode(['errors' => ['Not found']]))
        );

        $this->expectException(RequestVerificationException::class);
        $this->expectExceptionMessage('Expected request not found: GET /api/missing');

        $this->module->seeHttpRequest('GET', '/api/missing');
    }

    public function testDontSeeHttpRequestSucceedsWhenNotFound(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 0]))
        );

        $this->module->dontSeeHttpRequest('GET', '/api/never-called');

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testDontSeeHttpRequestThrowsWhenFound(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 1]))
        );

        $this->expectException(RequestVerificationException::class);
        $this->expectExceptionMessage('Unexpected request found: GET /api/called');

        $this->module->dontSeeHttpRequest('GET', '/api/called');
    }

    public function testSeeRequestCountVerifiesExactCount(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 3]))
        );

        $this->module->seeRequestCount(3, ['method' => 'GET', 'url' => '/api/test']);

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testSeeRequestCountThrowsOnMismatch(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 2]))
        );

        $this->expectException(RequestVerificationException::class);
        $this->expectExceptionMessage('Expected 5 request(s), but found 2');

        $this->module->seeRequestCount(5, ['method' => 'GET', 'url' => '/api/test']);
    }

    public function testGrabRequestCountReturnsCount(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['count' => 7]))
        );

        $count = $this->module->grabRequestCount(['method' => 'POST', 'url' => '/api/data']);

        $this->assertSame(7, $count);
    }

    public function testGrabAllRequestsReturnsRequestArray(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'requests' => [
                    ['method' => 'GET', 'url' => '/api/test'],
                    ['method' => 'POST', 'url' => '/api/data']
                ]
            ]))
        );

        $requests = $this->module->grabAllRequests();

        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('POST', $requests[1]['method']);
    }

    public function testGrabUnmatchedRequestsReturnsUnmatchedArray(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'requests' => [
                    ['method' => 'GET', 'url' => '/api/unknown']
                ]
            ]))
        );

        $unmatched = $this->module->grabUnmatchedRequests();

        $this->assertCount(1, $unmatched);
        $this->assertSame('/api/unknown', $unmatched[0]['url']);
    }

    public function testSendResetCallsResetEndpoint(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['status' => 'ok']))
        );

        $this->module->sendReset();

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testSendClearRequestsCallsClearEndpoint(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['status' => 'ok']))
        );

        $this->module->sendClearRequests();

        // Should not throw exception
        $this->assertTrue(true);
    }

    /**
     * Test determineUrlKey method for proper url vs urlPath selection
     *
     * @dataProvider urlKeyDataProvider
     */
    public function testDetermineUrlKey(array $matchers, string $expectedKey): void
    {
        $reflection = new ReflectionClass($this->module);
        $method = $reflection->getMethod('determineUrlKey');

        $result = $method->invoke($this->module, $matchers);

        $this->assertSame($expectedKey, $result);
    }

    /**
     * Data provider for testDetermineUrlKey
     *
     * @return Generator<string, array{matchers: array<string, mixed>, expectedKey: string}>
     */
    public static function urlKeyDataProvider(): Generator
    {
        yield 'no matchers - uses url' => [
            'matchers' => [],
            'expectedKey' => 'url',
        ];

        yield 'only method matcher - uses url' => [
            'matchers' => ['method' => 'GET'],
            'expectedKey' => 'url',
        ];

        yield 'with queryParameters - uses urlPath' => [
            'matchers' => [
                'queryParameters' => ['q' => ['equalTo' => 'London']],
            ],
            'expectedKey' => 'urlPath',
        ];

        yield 'with queryParameters and other matchers - uses urlPath' => [
            'matchers' => [
                'queryParameters' => ['q' => ['equalTo' => 'London']],
                'headers' => ['Content-Type' => ['equalTo' => 'application/json']],
            ],
            'expectedKey' => 'urlPath',
        ];

        yield 'explicit urlPath overrides - uses url (respects user choice)' => [
            'matchers' => [
                'queryParameters' => ['q' => ['equalTo' => 'London']],
                'urlPath' => '/api/weather',
            ],
            'expectedKey' => 'url',
        ];

        yield 'explicit urlPattern overrides - uses url (respects user choice)' => [
            'matchers' => [
                'queryParameters' => ['q' => ['equalTo' => 'London']],
                'urlPattern' => '/api/.*',
            ],
            'expectedKey' => 'url',
        ];

        yield 'only bodyPatterns - uses url' => [
            'matchers' => [
                'bodyPatterns' => [['equalToJson' => '{"name":"test"}']],
            ],
            'expectedKey' => 'url',
        ];

        yield 'only headers - uses url' => [
            'matchers' => [
                'headers' => ['Authorization' => ['matches' => 'Bearer .*']],
            ],
            'expectedKey' => 'url',
        ];
    }
}

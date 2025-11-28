<?php

declare(strict_types=1);

namespace Tests\Functional;

use Tests\FunctionalTester;

class WiremockIntegrationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->sendReset();
    }

    public function testCreateSimpleGetStub(FunctionalTester $I): void
    {
        // Create stub
        $stubId = $I->haveHttpStubFor('GET', '/api/test', 200, 'Hello World');
        $I->assertNotEmpty($stubId);

        // Make actual HTTP request to WireMock
        $response = file_get_contents('http://localhost:8080/api/test');

        // Verify response
        $I->assertEquals('Hello World', $response);

        // Verify request was recorded
        $I->seeHttpRequest('GET', '/api/test');
        $I->seeRequestCount(1, ['method' => 'GET', 'url' => '/api/test']);
    }

    public function testCreateJsonStub(FunctionalTester $I): void
    {
        // Create JSON stub
        $stubId = $I->haveHttpStubFor('GET', '/api/users/1', 200, [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $I->assertNotEmpty($stubId);

        // Make HTTP request
        $response = file_get_contents('http://localhost:8080/api/users/1');
        $data = json_decode($response, true);

        // Verify JSON response
        $I->assertEquals(1, $data['id']);
        $I->assertEquals('John Doe', $data['name']);
        $I->assertEquals('john@example.com', $data['email']);

        // Verify request
        $I->seeHttpRequest('GET', '/api/users/1');
    }

    public function testCreatePostStub(FunctionalTester $I): void
    {
        // Create POST stub
        $stubId = $I->haveHttpStubFor('POST', '/api/users', 201, ['success' => true]);
        $I->assertNotEmpty($stubId);

        // Make POST request
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(['name' => 'Jane Doe'])
            ]
        ]);

        $response = file_get_contents('http://localhost:8080/api/users', false, $context);
        $data = json_decode($response, true);

        $I->assertTrue($data['success']);
        $I->seeHttpRequest('POST', '/api/users');
    }

    public function testRequestBodyMatching(FunctionalTester $I): void
    {
        // Create stub with body pattern matching
        $I->haveHttpStubFor('POST', '/api/users', 201, ['created' => true], [], [
            'bodyPatterns' => [
                ['equalToJson' => ['name' => 'Jane Doe']]
            ]
        ]);

        // Make matching POST request
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(['name' => 'Jane Doe'])
            ]
        ]);

        $response = file_get_contents('http://localhost:8080/api/users', false, $context);
        $data = json_decode($response, true);

        $I->assertTrue($data['created']);

        // Verify with body pattern
        $I->seeHttpRequest('POST', '/api/users', [
            'bodyPatterns' => [
                ['equalToJson' => ['name' => 'Jane Doe']]
            ]
        ]);
    }

    public function testMultipleRequests(FunctionalTester $I): void
    {
        // Create stub
        $I->haveHttpStubFor('GET', '/api/data', 200, 'data');

        // Make multiple requests
        file_get_contents('http://localhost:8080/api/data');
        file_get_contents('http://localhost:8080/api/data');
        file_get_contents('http://localhost:8080/api/data');

        // Verify count
        $I->seeRequestCount(3, ['method' => 'GET', 'url' => '/api/data']);

        $count = $I->grabRequestCount(['method' => 'GET', 'url' => '/api/data']);
        $I->assertEquals(3, $count);
    }

    public function testGrabAllRequests(FunctionalTester $I): void
    {
        // Create stubs
        $I->haveHttpStubFor('GET', '/api/endpoint1', 200, 'response1');
        $I->haveHttpStubFor('GET', '/api/endpoint2', 200, 'response2');

        // Make requests
        file_get_contents('http://localhost:8080/api/endpoint1');
        file_get_contents('http://localhost:8080/api/endpoint2');

        // Grab all requests
        $requests = $I->grabAllRequests();

        $I->assertGreaterThanOrEqual(2, count($requests));
    }

    public function testUnmatchedRequests(FunctionalTester $I): void
    {
        // Make request without stub (will 404)
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true
            ]
        ]);
        @file_get_contents('http://localhost:8080/api/no-stub', false, $context);

        // Check unmatched requests
        $unmatched = $I->grabUnmatchedRequests();

        $I->assertNotEmpty($unmatched);
        $I->assertEquals('/api/no-stub', $unmatched[0]['url']);
    }

    public function testDontSeeHttpRequest(FunctionalTester $I): void
    {
        // Create stub but don't call it
        $I->haveHttpStubFor('GET', '/api/uncalled', 200, 'test');

        // Verify request was NOT made
        $I->dontSeeHttpRequest('GET', '/api/uncalled');
    }

    public function testClearRequests(FunctionalTester $I): void
    {
        // Create stub and make request
        $I->haveHttpStubFor('GET', '/api/test', 200, 'test');
        file_get_contents('http://localhost:8080/api/test');

        // Verify request exists
        $I->seeHttpRequest('GET', '/api/test');

        // Clear requests
        $I->sendClearRequests();

        // Verify request is gone
        $I->dontSeeHttpRequest('GET', '/api/test');

        // But stub should still exist
        $response = file_get_contents('http://localhost:8080/api/test');
        $I->assertEquals('test', $response);
    }

    public function testReset(FunctionalTester $I): void
    {
        // Create stub and make request
        $I->haveHttpStubFor('GET', '/api/reset-test', 200, 'test');
        file_get_contents('http://localhost:8080/api/reset-test');

        // Reset
        $I->sendReset();

        // Requests should be cleared
        $I->dontSeeHttpRequest('GET', '/api/reset-test');
    }

    public function testCustomHeaders(FunctionalTester $I): void
    {
        // Create stub with custom headers
        $I->haveHttpStubFor('GET', '/api/headers', 200, 'test', [
            'X-Custom-Header' => 'custom-value',
            'Content-Type' => 'text/plain'
        ]);

        // Make request and check headers
        $context = stream_context_create([
            'http' => [
                'header' => 'User-Agent: PHP'
            ]
        ]);

        file_get_contents('http://localhost:8080/api/headers', false, $context);

        // Check response headers (they're in $http_response_header)
        $I->assertContains('X-Custom-Header: custom-value', $http_response_header);
    }
}

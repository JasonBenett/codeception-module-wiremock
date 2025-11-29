<?php

declare(strict_types=1);

namespace JasonBenett\CodeceptionModuleWiremock\Module;

use Codeception\Exception\ModuleException;
use Codeception\Module;
use Codeception\TestInterface;
use JasonBenett\CodeceptionModuleWiremock\Exception\RequestVerificationException;
use JasonBenett\CodeceptionModuleWiremock\Exception\WiremockException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Wiremock extends Module
{
    /** HTTP status code threshold for error responses (400+) */
    private const HTTP_BAD_REQUEST = 400;

    /** @var array<string, mixed> Module configuration */
    protected array $config = [
        'host' => '127.0.0.1',
        'port' => 8080,
        'protocol' => 'http',
        'cleanupBefore' => 'test',      // Options: 'never', 'test', 'suite'
        'preserveFileMappings' => true,
        'adminPath' => '/__admin',
        'httpClient' => null,           // PSR-18 ClientInterface instance
        'requestFactory' => null,       // PSR-17 RequestFactoryInterface instance
        'streamFactory' => null,        // PSR-17 StreamFactoryInterface instance
    ];

    /** @var array<int, string> Required configuration fields */
    protected array $requiredFields = ['host', 'port'];

    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected string $baseUrl;

    /**
     * Initialize the module - validate PSR dependencies and verify connectivity
     *
     * @throws ModuleException If configuration is invalid or WireMock is not accessible
     */
    public function _initialize(): void
    {
        $protocol = $this->config['protocol'];
        $host = $this->config['host'];
        $port = $this->config['port'];
        $adminPath = $this->config['adminPath'];

        if (!is_string($protocol) || !is_string($host) || !is_int($port) || !is_string($adminPath)) {
            throw new ModuleException($this, 'Invalid configuration types');
        }

        $this->baseUrl = sprintf(
            '%s://%s:%d%s',
            $protocol,
            $host,
            $port,
            $adminPath,
        );

        $this->initHttpClient();
        $this->initRequestFactory();
        $this->initStreamFactory();

        try {
            $request = $this->requestFactory->createRequest('GET', $this->baseUrl . '/health');
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() >= self::HTTP_BAD_REQUEST) {
                throw new ModuleException(
                    $this,
                    sprintf('WireMock health check failed at %s/health', $this->baseUrl),
                );
            }
        } catch (ClientExceptionInterface $exception) {
            throw new ModuleException(
                $this,
                sprintf('Cannot connect to WireMock at %s: %s', $this->baseUrl, $exception->getMessage()),
            );
        }
    }

    /**
     * Hook executed before each suite
     *
     * @param array<string, mixed> $settings Suite settings
     *
     * @throws WiremockException If WireMock communication fails during cleanup
     * @throws JsonException If JSON encoding/decoding fails during cleanup
     */
    public function _beforeSuite($settings = []): void
    {
        if ($this->config['cleanupBefore'] === 'suite') {
            $this->cleanup();
        }
    }

    /**
     * Hook executed before each test
     *
     * @throws WiremockException If WireMock communication fails during cleanup
     * @throws JsonException If JSON encoding/decoding fails during cleanup
     */
    public function _before(TestInterface $test): void
    {
        if ($this->config['cleanupBefore'] === 'test') {
            $this->cleanup();
        }
    }

    /**
     * Create an HTTP stub for any HTTP method
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url URL or URL pattern to match
     * @param int $status HTTP status code to return (default: 200)
     * @param string|array<string, mixed> $body Response body (string or array for JSON)
     * @param array<string, string> $headers Response headers (header name => value)
     * @param array<string, mixed> $requestMatchers Additional request matching criteria (bodyPatterns, headers, queryParameters, etc.)
     *
     * @return string UUID of created stub mapping
     *
     * @throws WiremockException If stub creation fails or WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function haveHttpStubFor(
        string       $method,
        string       $url,
        int          $status = 200,
        string|array $body = '',
        array        $headers = [],
        array        $requestMatchers = [],
    ): string {
        $mapping = [
            'request' => array_merge([
                'method' => strtoupper($method),
                'url' => $url,
            ], $requestMatchers),
            'response' => [
                'status' => $status,
                'headers' => $headers,
            ],
        ];

        // Handle body based on type
        if (is_array($body)) {
            $mapping['response']['jsonBody'] = $body;

            if (!isset($headers['Content-Type'])) {
                $mapping['response']['headers']['Content-Type'] = 'application/json';
            }
        } elseif ($body !== '') {
            $mapping['response']['body'] = $body;
        }

        $response = $this->makeAdminRequest('POST', 'mappings', $mapping);

        if (!isset($response['id']) || !is_string($response['id'])) {
            throw new WiremockException('Failed to create stub mapping: no ID returned');
        }

        $this->debugSection('WireMock', "Created stub: {$method} {$url} -> {$status}");

        return $response['id'];
    }

    /**
     * Verify that an HTTP request was made
     *
     * @param string $method HTTP method
     * @param string $url URL or URL pattern
     * @param array<string, mixed> $additionalMatchers Additional matching criteria (bodyPatterns, headers, queryParameters, etc.)
     *
     * @throws RequestVerificationException If the expected request was not found
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function seeHttpRequest(
        string $method,
        string $url,
        array  $additionalMatchers = [],
    ): void {
        $pattern = array_merge([
            'method' => strtoupper($method),
            'url' => $url,
        ], $additionalMatchers);

        $count = $this->grabRequestCount($pattern);

        if ($count === 0) {
            // Try to get near misses for better error message
            $nearMissesData = $this->fetchNearMisses($pattern);
            $message = sprintf('Expected request not found: %s %s', $method, $url);

            $nearMisses = $nearMissesData['nearMisses'] ?? null;
            if (is_array($nearMisses) && !empty($nearMisses)) {
                /** @var array<int, array<string, mixed>> $nearMisses */
                $message = sprintf("%s\n\nNear misses found:\n%s", $message, $this->formatNearMisses($nearMisses));
            }

            throw new RequestVerificationException($message);
        }

        $this->debugSection('WireMock', "Request verified: {$method} {$url} (found {$count} match(es))");
    }

    /**
     * Verify that an HTTP request was NOT made
     *
     * @param string $method HTTP method
     * @param string $url URL or URL pattern
     * @param array<string, mixed> $additionalMatchers Additional matching criteria (bodyPatterns, headers, queryParameters, etc.)
     *
     * @throws RequestVerificationException If the unexpected request was found
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function dontSeeHttpRequest(
        string $method,
        string $url,
        array  $additionalMatchers = [],
    ): void {
        $pattern = array_merge([
            'method' => strtoupper($method),
            'url' => $url,
        ], $additionalMatchers);

        $count = $this->grabRequestCount($pattern);

        if ($count > 0) {
            throw new RequestVerificationException(
                sprintf('Unexpected request found: %s %s (found %d match(es))', $method, $url, $count),
            );
        }

        $this->debugSection('WireMock', "Request not found (as expected): {$method} {$url}");
    }

    /**
     * Assert exact number of requests matching criteria
     *
     * @param int $expectedCount Expected number of requests
     * @param array<string, mixed> $requestPattern Request matching pattern (method, url, bodyPatterns, headers, etc.)
     *
     * @throws RequestVerificationException If the actual count does not match expected count
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function seeRequestCount(int $expectedCount, array $requestPattern): void
    {
        $actualCount = $this->grabRequestCount($requestPattern);

        if ($actualCount !== $expectedCount) {
            throw new RequestVerificationException(
                sprintf('Expected %d request(s), but found %d', $expectedCount, $actualCount),
            );
        }

        $this->debugSection('WireMock', "Request count verified: {$actualCount} match(es)");
    }

    /**
     * Get count of requests matching criteria
     *
     * @param array<string, mixed> $requestPattern Request matching pattern (method, url, bodyPatterns, headers, etc.)
     *
     * @return int Count of matching requests
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function grabRequestCount(array $requestPattern): int
    {
        $response = $this->makeAdminRequest('POST', 'requests/count', $requestPattern);
        $count = $response['count'] ?? 0;

        return is_int($count) ? $count : 0;
    }

    /**
     * Get all recorded requests
     *
     * @return array<int, array<string, mixed>> Array of request objects with method, url, headers, body, etc.
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function grabAllRequests(): array
    {
        $response = $this->makeAdminRequest('GET', 'requests');
        $requests = $response['requests'] ?? [];

        if (!is_array($requests)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $requests */
        return $requests;
    }

    /**
     * Get requests that didn't match any stub (returned 404)
     *
     * @return array<int, array<string, mixed>> Array of unmatched request objects with method, url, headers, body, etc.
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function grabUnmatchedRequests(): array
    {
        $response = $this->makeAdminRequest('GET', 'requests/unmatched');
        $requests = $response['requests'] ?? [];

        if (!is_array($requests)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $requests */
        return $requests;
    }

    /**
     * Reset WireMock to default state
     * Preserves file-based stub mappings if configured
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function sendReset(): void
    {
        $this->makeAdminRequest('POST', 'mappings/reset');
        $this->debugSection('WireMock', 'Reset to default state');
    }

    /**
     * Clear the request journal without affecting stub mappings
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    public function sendClearRequests(): void
    {
        $this->makeAdminRequest('DELETE', 'requests');
        $this->debugSection('WireMock', 'Cleared request journal');
    }

    /**
     * Internal cleanup method called by lifecycle hooks
     *
     * @throws WiremockException If WireMock communication fails
     * @throws JsonException If JSON encoding/decoding fails
     */
    protected function cleanup(): void
    {
        if ($this->config['preserveFileMappings']) {
            $this->sendReset();

            return;
        }

        // Full reset: both mappings and requests
        $this->makeAdminRequest('POST', 'reset');
    }

    /**
     * Make HTTP request to WireMock Admin API using PSR-18/PSR-17
     *
     * @param string $method HTTP method
     * @param string $endpoint Admin API endpoint (relative to admin path)
     * @param array<string, mixed> $data Request body data
     *
     * @return array<string, mixed> Response data decoded from JSON
     *
     * @throws WiremockException If WireMock request fails or communication fails
     * @throws JsonException If JSON encoding fails
     */
    protected function makeAdminRequest(
        string $method,
        string $endpoint,
        array  $data = [],
    ): array {
        $uri = $this->baseUrl . '/' . ltrim($endpoint, '/');

        try {
            $request = $this->requestFactory->createRequest($method, $uri);

            if (!empty($data)) {
                $jsonBody = json_encode($data, JSON_THROW_ON_ERROR);
                $stream = $this->streamFactory->createStream($jsonBody);
                $request = $request
                    ->withBody($stream)
                    ->withHeader('Content-Type', 'application/json');
            }

            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode >= self::HTTP_BAD_REQUEST) {
                throw new WiremockException(
                    sprintf(
                        'WireMock request failed with status %d: %s',
                        $statusCode,
                        $body,
                    ),
                );
            }

            if ($body === '') {
                return [];
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;

        } catch (ClientExceptionInterface $exception) {
            throw new WiremockException(
                sprintf('Failed to communicate with WireMock: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * Fetch near-miss analysis for debugging
     *
     * @param array<string, mixed> $requestPattern Request pattern to analyze
     *
     * @return array<string, mixed> Near-miss analysis data with nearMisses array
     *
     * @throws JsonException If JSON encoding/decoding fails
     */
    protected function fetchNearMisses(array $requestPattern): array
    {
        try {
            return $this->makeAdminRequest('POST', 'near-misses/request', $requestPattern);
        } catch (WiremockException) {
            // If near-misses endpoint fails, just return empty
            return [];
        }
    }

    /**
     * Format near-miss data for error messages
     *
     * @param array<int, array<string, mixed>> $nearMisses Near-miss data from WireMock with request details
     *
     * @return string Formatted string with numbered near-miss entries
     */
    protected function formatNearMisses(array $nearMisses): string
    {
        $output = [];

        foreach (array_slice($nearMisses, 0, 3) as $index => $nearMiss) {
            $request = $nearMiss['request'] ?? [];

            $method = 'UNKNOWN';
            if (is_array($request) && isset($request['method'])) {
                $methodValue = $request['method'];
                $method = is_string($methodValue) || is_numeric($methodValue) ? (string) $methodValue : 'UNKNOWN';
            }

            $url = 'unknown';
            if (is_array($request) && isset($request['url'])) {
                $urlValue = $request['url'];
                $url = is_string($urlValue) || is_numeric($urlValue) ? (string) $urlValue : 'unknown';
            }

            $output[] = sprintf(
                '%d. %s %s',
                $index + 1,
                $method,
                $url,
            );

            $matchResult = $nearMiss['matchResult'] ?? null;
            if (is_array($matchResult) && isset($matchResult['distance'])) {
                $distance = $matchResult['distance'];
                $output[] = '   Distance: ' . (is_scalar($distance) ? (string) $distance : '?');
            }
        }

        return implode("\n", $output);
    }

    /**
     * Get HTTP client from config or create default Guzzle client
     *
     * @return void
     *
     * @throws ModuleException If no client provided and Guzzle is not available
     */
    private function initHttpClient(): void
    {
        $httpClient = $this->config['httpClient'];

        if ($httpClient === null) {
            if (!class_exists('\\GuzzleHttp\\Client')) {
                throw new ModuleException(
                    $this,
                    'No httpClient provided and GuzzleHTTP is not available. Either provide a PSR-18 ClientInterface or install guzzlehttp/guzzle.',
                );
            }

            $this->httpClient = new \GuzzleHttp\Client([
                'timeout' => 10.0,
                'http_errors' => false,
            ]);

            return;
        }

        if (!$httpClient instanceof ClientInterface) {
            throw new ModuleException(
                $this,
                sprintf('Configuration "httpClient" must be an instance of %s', ClientInterface::class),
            );
        }

        $this->httpClient = $httpClient;
    }

    /**
     * Get request factory from config or create default Guzzle factory
     *
     * @return void
     *
     * @throws ModuleException If no factory provided and Guzzle PSR-7 is not available
     */
    private function initRequestFactory(): void
    {
        $requestFactory = $this->config['requestFactory'];

        if ($requestFactory === null) {
            if (!class_exists('\\GuzzleHttp\\Psr7\\HttpFactory')) {
                throw new ModuleException(
                    $this,
                    'No requestFactory provided and GuzzleHTTP PSR-17 factory is not available. Either provide a PSR-17 RequestFactoryInterface or install guzzlehttp/psr7.',
                );
            }

            $this->requestFactory = new \GuzzleHttp\Psr7\HttpFactory();

            return;
        }

        if (!$requestFactory instanceof RequestFactoryInterface) {
            throw new ModuleException(
                $this,
                sprintf('Configuration "requestFactory" must be an instance of %s', RequestFactoryInterface::class),
            );
        }

        $this->requestFactory = $requestFactory;
    }

    /**
     * Get stream factory from config or create default factory
     *
     * @return void
     *
     * @throws ModuleException If no factory provided and no compatible factory available
     */
    private function initStreamFactory(): void
    {
        $streamFactory = $this->config['streamFactory'];

        if ($streamFactory === null) {
            if ($this->requestFactory instanceof StreamFactoryInterface) {
                $this->streamFactory = $this->requestFactory;

                return;
            }

            // Otherwise create Guzzle PSR-17 factory
            if (class_exists('\\GuzzleHttp\\Psr7\\HttpFactory')) {
                $this->streamFactory = new \GuzzleHttp\Psr7\HttpFactory();

                return;
            }

            throw new ModuleException(
                $this,
                'No streamFactory provided and GuzzleHTTP PSR-17 factory is not available. Either provide a PSR-17 StreamFactoryInterface or install guzzlehttp/psr7.',
            );
        }

        if (!$streamFactory instanceof StreamFactoryInterface) {
            throw new ModuleException(
                $this,
                sprintf('Configuration "streamFactory" must be an instance of %s', StreamFactoryInterface::class),
            );
        }

        $this->streamFactory = $streamFactory;
    }
}

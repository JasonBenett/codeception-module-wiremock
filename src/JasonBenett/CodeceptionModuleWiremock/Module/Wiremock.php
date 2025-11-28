<?php

declare(strict_types=1);

namespace JasonBenett\CodeceptionModuleWiremock\Module;

use Codeception\Exception\ModuleException;
use Codeception\Module;
use Codeception\TestInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JasonBenett\CodeceptionModuleWiremock\Exception\RequestVerificationException;
use JasonBenett\CodeceptionModuleWiremock\Exception\WiremockException;
use JsonException;

class Wiremock extends Module
{
    /** @var array<string, mixed> Module configuration */
    protected array $config = [
        'host' => '127.0.0.1',
        'port' => 8080,
        'protocol' => 'http',
        'timeout' => 10.0,
        'cleanupBefore' => 'test',      // Options: 'never', 'test', 'suite'
        'preserveFileMappings' => true,
        'verifySSL' => true,
        'adminPath' => '/__admin',
    ];

    /** @var array<int, string> Required configuration fields */
    protected array $requiredFields = ['host', 'port'];

    protected ?Client $client = null;
    protected string $baseUrl;

    /**
     * Initialize the module - create HTTP client and verify connectivity
     *
     * @throws ModuleException
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

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => $this->config['timeout'],
            'verify' => $this->config['verifySSL'],
            'http_errors' => false,
        ]);

        // Verify WireMock is accessible
        try {
            $response = $this->client->request('GET', 'health');

            if ($response->getStatusCode() >= 400) {
                throw new ModuleException(
                    $this,
                    "WireMock health check failed at {$this->baseUrl}/health",
                );
            }
        } catch (GuzzleException $exception) {
            throw new ModuleException(
                $this,
                "Cannot connect to WireMock at {$this->baseUrl}: " . $exception->getMessage(),
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
            $message = "Expected request not found: {$method} {$url}";

            $nearMisses = $nearMissesData['nearMisses'] ?? null;
            if (is_array($nearMisses) && !empty($nearMisses)) {
                /** @var array<int, array<string, mixed>> $nearMisses */
                $message .= "\n\nNear misses found:\n" . $this->formatNearMisses($nearMisses);
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
                "Unexpected request found: {$method} {$url} (found {$count} match(es))",
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
                "Expected {$expectedCount} request(s), but found {$actualCount}",
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
     * Make HTTP request to WireMock Admin API
     *
     * @param string $method HTTP method
     * @param string $endpoint Admin API endpoint (relative to admin path)
     * @param array<string, mixed> $data Request body data
     *
     * @return array<string, mixed> Response data decoded from JSON
     *
     * @throws WiremockException If WireMock request fails or communication fails
     * @throws JsonException If JSON decoding fails
     */
    protected function makeAdminRequest(
        string $method,
        string $endpoint,
        array  $data = [],
    ): array {
        if ($this->client === null) {
            throw new WiremockException('HTTP client is not initialized');
        }

        try {
            $options = [];

            if (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->client->request($method, $endpoint, $options);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode >= 400) {
                throw new WiremockException(
                    "WireMock request failed with status {$statusCode}: {$body}",
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

        } catch (GuzzleException $exception) {
            throw new WiremockException(
                'Failed to communicate with WireMock: ' . $exception->getMessage(),
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
}

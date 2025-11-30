# Codeception Module WireMock

[![CI](https://github.com/jasonbenett/codeception-module-wiremock/actions/workflows/ci.yml/badge.svg)](https://github.com/jasonbenett/codeception-module-wiremock/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/jasonbenett/codeception-module-wiremock/branch/main/graph/badge.svg)](https://codecov.io/gh/jasonbenett/codeception-module-wiremock)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![Codeception](https://img.shields.io/badge/codeception-%3E%3D5.3-green.svg)](https://codeception.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A Codeception module for WireMock integration, allowing you to mock HTTP services in your functional tests.

## Features

- Create HTTP stubs for any HTTP method (GET, POST, PUT, DELETE, etc.)
- Verify that expected HTTP requests were made
- Advanced request matching (body patterns, headers, query parameters)
- Retrieve request data for debugging
- Automatic cleanup between tests
- Near-miss analysis for debugging failed verifications
- Follows Codeception naming conventions

## Requirements

- PHP 8.2 or higher
- Codeception 5.3 or higher
- A PSR-18 HTTP Client implementation (e.g., Guzzle, Symfony HttpClient)
- A PSR-17 HTTP Factory implementation (e.g., guzzlehttp/psr7)
- A running WireMock server

## Installation

Install via Composer:

```bash
composer require jasonbenett/codeception-module-wiremock
```

This module depends on PSR-18 (HTTP Client) and PSR-17 (HTTP Factories) interfaces. You'll need to install a compatible implementation:

**Using Guzzle (recommended):**
```bash
composer require guzzlehttp/guzzle
```

**Using Symfony HttpClient:**
```bash
composer require symfony/http-client nyholm/psr7
```

**Other PSR-18/PSR-17 implementations work as well.**

## Architecture

This module follows **PSR-18** (HTTP Client) and **PSR-17** (HTTP Factories) standards, providing true dependency inversion:

- **No hard dependency on Guzzle** - Use any PSR-compliant HTTP client
- **Framework agnostic** - Works with Symfony HttpClient, Guzzle, or custom clients
- **Optional auto-discovery** - Automatically creates Guzzle instances if available
- **Full control** - Inject your own configured PSR clients for advanced scenarios

This approach allows you to:
- Choose your preferred HTTP client library
- Control HTTP client configuration (timeouts, SSL, proxies, etc.)
- Test with mock PSR-18 clients
- Upgrade HTTP client versions independently

## Quick Start

### 1. Start WireMock Server

Using Docker (recommended):

```bash
docker run -d -p 8080:8080 wiremock/wiremock:latest
```

### 2. Configure Codeception

Add the WireMock module to your `codeception.yml` or suite configuration:

```yaml
modules:
  enabled:
    - \JasonBenett\CodeceptionModuleWiremock\Module\Wiremock:
        host: localhost
        port: 8080
```

### 3. Write Your First Test

```php
<?php

class ApiTestCest
{
    public function testUserEndpoint(FunctionalTester $I)
    {
        // Create a stub for GET /api/users/1
        $I->haveHttpStubFor('GET', '/api/users/1', 200, [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);

        // Your application makes the HTTP request
        // ... your application code ...

        // Verify the request was made
        $I->seeHttpRequest('GET', '/api/users/1');
    }
}
```

## Configuration Options

### Basic Configuration (Auto-Discovery)

When using Guzzle, the module can auto-create PSR client instances:

```yaml
modules:
  enabled:
    - \JasonBenett\CodeceptionModuleWiremock\Module\Wiremock:
        host: 127.0.0.1              # WireMock server host
        port: 8080                   # WireMock server port
        protocol: http               # Protocol (http or https)
        cleanupBefore: test          # When to cleanup: 'never', 'test', or 'suite'
        preserveFileMappings: true   # Keep file-based stubs on reset
        adminPath: /__admin          # Admin API path
```

### Advanced Configuration (Custom PSR Clients)

For full control and dependency inversion, provide your own PSR-18/PSR-17 implementations:

```php
// tests/_bootstrap.php or tests/_support/Helper/HttpClientProvider.php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

// Create PSR-18 HTTP Client
$httpClient = new Client([
    'timeout' => 10.0,
    'verify' => false,  // Disable SSL verification if needed
    'http_errors' => false,
]);

// Create PSR-17 factories (Guzzle's HttpFactory implements both interfaces)
$httpFactory = new HttpFactory();

// Store in global for Codeception access
$GLOBALS['wiremock_http_client'] = $httpClient;
$GLOBALS['wiremock_request_factory'] = $httpFactory;
$GLOBALS['wiremock_stream_factory'] = $httpFactory;
```

```yaml
# codeception.yml or suite config
modules:
  enabled:
    - \JasonBenett\CodeceptionModuleWiremock\Module\Wiremock:
        host: 127.0.0.1
        port: 8080
        httpClient: !php/const GLOBALS['wiremock_http_client']
        requestFactory: !php/const GLOBALS['wiremock_request_factory']
        streamFactory: !php/const GLOBALS['wiremock_stream_factory']
```

### All Configuration Options

```yaml
host: 127.0.0.1                  # Required: WireMock server host
port: 8080                       # Required: WireMock server port
protocol: http                   # Optional: Protocol (http or https)
cleanupBefore: test              # Optional: When to cleanup: 'never', 'test', or 'suite'
preserveFileMappings: true       # Optional: Keep file-based stubs on reset
adminPath: /__admin              # Optional: Admin API path
httpClient: null                 # Optional: PSR-18 ClientInterface instance
requestFactory: null             # Optional: PSR-17 RequestFactoryInterface instance
streamFactory: null              # Optional: PSR-17 StreamFactoryInterface instance
```

**Note:** If `httpClient`, `requestFactory`, or `streamFactory` are not provided, the module will attempt to auto-create Guzzle instances if available.

## Available Methods

### Setup Methods (have*)

#### haveHttpStubFor
Create an HTTP stub for any HTTP method with advanced request matching.

```php
$stubId = $I->haveHttpStubFor(
    string $method,                // HTTP method (GET, POST, PUT, DELETE, etc.)
    string $url,                   // URL or URL pattern
    int $status = 200,             // HTTP status code
    $body = '',                    // Response body
    array $headers = [],           // Response headers
    array $requestMatchers = []    // Additional request matching criteria
): string;                         // Returns stub UUID
```

**Examples:**

```php
// Simple POST stub
$I->haveHttpStubFor('POST', '/api/users', 201, ['success' => true]);

// POST with body pattern matching
$I->haveHttpStubFor('POST', '/api/users', 201, ['created' => true], [], [
    'bodyPatterns' => [
        ['equalToJson' => ['name' => 'Jane Doe', 'email' => 'jane@example.com']]
    ]
]);

// PUT with header matching
$I->haveHttpStubFor('PUT', '/api/users/1', 200, ['updated' => true], [], [
    'headers' => [
        'Authorization' => [
            'matches' => 'Bearer .*'
        ]
    ]
]);

// DELETE with query parameters
$I->haveHttpStubFor('DELETE', '/api/users', 204, '', [], [
    'queryParameters' => [
        'id' => ['equalTo' => '123']
    ]
]);
```

### Assertion Methods (see* / dontSee*)

#### seeHttpRequest
Verify that an HTTP request was made.

```php
$I->seeHttpRequest(
    string $method,                // HTTP method
    string $url,                   // URL or URL pattern
    array $additionalMatchers = [] // Additional matching criteria
): void;
```

**Examples:**

```php
// Basic verification
$I->seeHttpRequest('GET', '/api/users');

// With body verification
$I->seeHttpRequest('POST', '/api/users', [
    'bodyPatterns' => [
        ['contains' => 'john@example.com']
    ]
]);

// With header verification
$I->seeHttpRequest('GET', '/api/data', [
    'headers' => [
        'Authorization' => ['matches' => 'Bearer .*']
    ]
]);
```

#### dontSeeHttpRequest
Verify that an HTTP request was NOT made.

```php
$I->dontSeeHttpRequest(
    string $method,                // HTTP method
    string $url,                   // URL or URL pattern
    array $additionalMatchers = [] // Additional matching criteria
): void;
```

**Example:**

```php
// Verify endpoint was not called
$I->dontSeeHttpRequest('DELETE', '/api/users/1');
```

#### seeRequestCount
Assert exact number of requests matching criteria.

```php
$I->seeRequestCount(
    int $expectedCount,       // Expected number of requests
    array $requestPattern     // Request matching pattern
): void;
```

**Examples:**

```php
// Verify exactly 3 requests
$I->seeRequestCount(3, ['method' => 'GET', 'url' => '/api/data']);

// Verify no requests to endpoint
$I->seeRequestCount(0, ['method' => 'DELETE', 'url' => '/api/users']);
```

### Data Retrieval Methods (grab*)

#### grabRequestCount
Get count of requests matching criteria.

```php
$count = $I->grabRequestCount(
    array $requestPattern    // Request matching pattern
): int;
```

**Example:**

```php
$count = $I->grabRequestCount(['method' => 'POST', 'url' => '/api/users']);
codecept_debug("Received {$count} POST requests");
```

#### grabAllRequests
Retrieve all recorded requests.

```php
$requests = $I->grabAllRequests(): array;
```

**Example:**

```php
$requests = $I->grabAllRequests();
foreach ($requests as $request) {
    codecept_debug($request['method'] . ' ' . $request['url']);
}
```

#### grabUnmatchedRequests
Get requests that didn't match any stub (returned 404).

```php
$unmatched = $I->grabUnmatchedRequests(): array;
```

**Example:**

```php
$unmatched = $I->grabUnmatchedRequests();
if (!empty($unmatched)) {
    codecept_debug('Unmatched requests:', $unmatched);
}
```

### Action Methods (send*)

#### sendReset
Reset WireMock to default state (preserves file-based stubs if configured).

```php
$I->sendReset(): void;
```

#### sendClearRequests
Clear the request journal without affecting stub mappings.

```php
$I->sendClearRequests(): void;
```

## Request Matching Patterns

WireMock supports powerful request matching. Here are common patterns:

### URL Matching

```php
// Exact match
['url' => '/exact/path']

// Regex pattern
['urlPattern' => '/api/users/.*']

// Path only (ignores query params)
['urlPath' => '/api/users']

// Path with regex
['urlPathPattern' => '/api/.*/items']
```

### Body Matching

```php
'bodyPatterns' => [
    ['equalTo' => 'exact string'],
    ['contains' => 'substring'],
    ['matches' => 'regex.*pattern'],
    ['equalToJson' => ['key' => 'value']],
    ['matchesJsonPath' => '$.store.book[?(@.price < 10)]'],
    ['equalToXml' => '<root>...</root>']
]
```

### Header Matching

```php
'headers' => [
    'Content-Type' => ['equalTo' => 'application/json'],
    'Authorization' => ['matches' => 'Bearer .*'],
    'X-Custom' => ['contains' => 'value']
]
```

## Complete Example

```php
<?php

class ShoppingCartCest
{
    public function testAddItemToCart(FunctionalTester $I)
    {
        // Setup: Create stub for adding item
        $I->haveHttpStubFor('POST', '/api/cart/items', 201,
            ['id' => 123, 'quantity' => 1],
            ['Content-Type' => 'application/json'],
            [
                'bodyPatterns' => [
                    ['matchesJsonPath' => '$.productId'],
                    ['matchesJsonPath' => '$.quantity']
                ]
            ]
        );

        // Setup: Create stub for getting cart
        $I->haveHttpStubFor('GET', '/api/cart', 200, [
            'items' => [
                ['id' => 123, 'productId' => 'PROD-1', 'quantity' => 1]
            ],
            'total' => 29.99
        ]);

        // Act: Your application code that interacts with the API
        // $cartService->addItem('PROD-1', 1);
        // $cart = $cartService->getCart();

        // Assert: Verify the expected requests were made
        $I->seeHttpRequest('POST', '/api/cart/items', [
            'bodyPatterns' => [
                ['matchesJsonPath' => '$.productId']
            ]
        ]);

        $I->seeHttpRequest('GET', '/api/cart');

        // Verify request count
        $I->seeRequestCount(1, ['method' => 'POST', 'url' => '/api/cart/items']);

        // Debug: Check all requests if needed
        $allRequests = $I->grabAllRequests();
        codecept_debug('Total requests made:', count($allRequests));
    }

    public function testEmptyCart(FunctionalTester $I)
    {
        // Verify no cart operations were performed
        $I->dontSeeHttpRequest('POST', '/api/cart/items');
        $I->dontSeeHttpRequest('GET', '/api/cart');
    }
}
```

## Local Development

### Using Docker

You can run WireMock using Docker:

```bash
# Start WireMock
docker run -d -p 8080:8080 wiremock/wiremock:latest

# Check status
docker ps | grep wiremock

# View logs
docker logs <container-id>

# Stop WireMock
docker stop <container-id>
```

### Running Tests

```bash
# Install dependencies
composer install

# Run unit tests (don't require WireMock)
composer test

# Start WireMock
docker run -d -p 8080:8080 wiremock/wiremock:latest

# Build Codeception support classes
vendor/bin/codecept build

# Run functional tests (require WireMock)
composer test:functional
```

## Debugging

### Near-Miss Analysis

When a verification fails, the module automatically includes near-miss analysis in the error message:

```
Expected request not found: GET /api/users

Near misses found:
1. GET /api/user
   Distance: 0.1
2. GET /api/users/1
   Distance: 0.2
```

### Check Unmatched Requests

```php
$unmatched = $I->grabUnmatchedRequests();
if (!empty($unmatched)) {
    codecept_debug('These requests did not match any stub:');
    foreach ($unmatched as $request) {
        codecept_debug($request['method'] . ' ' . $request['url']);
    }
}
```

### View All Requests

```php
$allRequests = $I->grabAllRequests();
codecept_debug('All requests made:', $allRequests);
```

## Quality Assurance

This project maintains high code quality standards with comprehensive automated checks:

### Continuous Integration

Every push and pull request is automatically tested via GitHub Actions across multiple PHP versions:

- ✅ **PHP 8.2, 8.3, 8.4** - Full compatibility testing
- ✅ **PHPStan Level Max** - Zero errors in static analysis
- ✅ **PER Coding Style 3.0** - Strict code style compliance
- ✅ **Testing** - Unit and Functional
- ✅ **WireMock Integration Tests** - Tests against real WireMock server

### Local Development

Run all quality checks before submitting:

```bash
# Static analysis
composer phpstan

# Code style check
composer cs-check

# Fix code style issues
composer cs-fix
# Run tests
composer test
composer test:functional
```

### Code Quality Metrics

- **PHPStan**: Max level, zero errors
- **Code Coverage**: with Codecov reporting
- **Code Style**: PER Coding Style 3.0 (successor to PSR-12)
- **Type Safety**: Full PHPDoc annotations with array shapes
- **Documentation**: Comprehensive inline documentation

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines including:

- Development workflow and setup
- Code quality standards
- Testing requirements
- Commit message conventions
- Pull request process

## License

MIT

## Links

- [WireMock Documentation](https://wiremock.org/docs/)
- [Codeception Documentation](https://codeception.com/docs/)
- [GitHub Repository](https://github.com/jasonbenett/codeception-module-wiremock)
- [Contributing Guidelines](CONTRIBUTING.md)

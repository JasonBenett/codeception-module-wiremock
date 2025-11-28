# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Codeception module for integrating with WireMock, a tool for mocking HTTP services in tests. The module extends Codeception's base `Module` class to provide WireMock functionality to Codeception test suites.

**Key Details:**
- PHP 8.2+ required
- Codeception 5.3+ required
- Guzzle HTTP client 7.8+ required
- MIT licensed
- PSR-4 autoloading structure

## Project Structure

```
src/JasonBenett/CodeceptionModuleWiremock/
├── Module/
│   └── Wiremock.php                    # Main module class with all public methods
└── Exception/
    ├── WiremockException.php           # Base exception
    └── RequestVerificationException.php # Verification failure exception

tests/
├── unit/
│   └── Codeception/Module/
│       └── WiremockTest.php            # Unit tests with mocked HTTP client
├── functional/
│   └── WiremockIntegrationCest.php     # Integration tests against real WireMock
└── _support/
    └── FunctionalTester.php            # Functional tester actor
```

## Development Commands

### Dependency Management
```bash
composer install          # Install dependencies
composer update          # Update dependencies
composer dump-autoload   # Regenerate autoload files
```

### Testing
```bash
composer test                  # Run unit tests
composer test:functional       # Run functional tests (requires WireMock running)
composer phpstan               # Run static analysis
composer cs-check              # Check code style
composer cs-fix                # Fix code style issues
```

### WireMock Server
```bash
docker-compose up -d           # Start WireMock server on port 8080
docker-compose down            # Stop WireMock server
docker-compose logs wiremock   # View WireMock logs
```

## Module Architecture

### Configuration Options

The module accepts the following configuration in `codeception.yml`:

```yaml
modules:
  enabled:
    - \JasonBenett\CodeceptionModuleWiremock\Module\Wiremock:
        host: localhost                  # WireMock host (default: 127.0.0.1)
        port: 8080                       # WireMock port (default: 8080)
        protocol: http                   # Protocol (default: http)
        timeout: 10.0                    # Request timeout in seconds (default: 10.0)
        cleanupBefore: test              # When to cleanup: 'never', 'test', or 'suite' (default: test)
        preserveFileMappings: true       # Keep file-based stubs on reset (default: true)
        verifySSL: true                  # Verify SSL certificates (default: true)
        adminPath: /__admin              # Admin API path (default: /__admin)
```

### Public Methods (MVP)

#### Setup Methods (have*)
- `haveHttpStubFor(string $method, string $url, int $status = 200, $body = '', array $headers = [], array $requestMatchers = []): string` - Create stub for any HTTP method

#### Assertion Methods (see*)
- `seeHttpRequest(string $method, string $url, array $additionalMatchers = []): void` - Verify request was made
- `dontSeeHttpRequest(string $method, string $url, array $additionalMatchers = []): void` - Verify request was NOT made
- `seeRequestCount(int $count, array $requestPattern): void` - Assert exact request count

#### Data Retrieval Methods (grab*)
- `grabRequestCount(array $requestPattern): int` - Get request count
- `grabAllRequests(): array` - Get all recorded requests
- `grabUnmatchedRequests(): array` - Get requests that didn't match any stub

#### Action Methods (send*)
- `sendReset(): void` - Reset stubs to default (preserves file-based mappings)
- `sendClearRequests(): void` - Clear request journal without affecting stubs

### Lifecycle Hooks

- `_initialize()` - Creates Guzzle HTTP client and verifies WireMock connectivity
- `_beforeSuite()` - Cleanup if `cleanupBefore: suite`
- `_before()` - Cleanup if `cleanupBefore: test` (default behavior)

### WireMock Admin API Endpoints Used

- `POST /__admin/mappings` - Create stub mapping
- `POST /__admin/mappings/reset` - Reset to defaults
- `POST /__admin/reset` - Full reset (mappings + requests)
- `DELETE /__admin/requests` - Clear request journal
- `POST /__admin/requests/count` - Count matching requests
- `GET /__admin/requests` - Get all requests
- `GET /__admin/requests/unmatched` - Get unmatched requests
- `POST /__admin/near-misses/request` - Get near-miss analysis
- `GET /__admin/health` - Health check

## Local Development

### Running Tests

1. Start WireMock server:
   ```bash
   docker-compose up -d
   ```

2. Wait for WireMock to be healthy:
   ```bash
   docker-compose ps
   # Should show "healthy" status
   ```

3. Run unit tests (don't require WireMock):
   ```bash
   composer test
   ```

4. Build Codeception support classes:
   ```bash
   vendor/bin/codecept build
   ```

5. Run functional tests (require WireMock):
   ```bash
   composer test:functional
   ```

### Debugging Tests

- Use `grabAllRequests()` to see all requests made
- Use `grabUnmatchedRequests()` to see requests that didn't match any stub
- Check WireMock logs: `docker-compose logs wiremock`
- Near-miss analysis is automatically included in verification error messages

## Common Patterns

### Basic Stub Creation
```php
$I->haveHttpStubFor('GET', '/api/test', 200, 'Hello World');
$I->seeHttpRequest('GET', '/api/test');
```

### JSON Stub
```php
$I->haveHttpStubFor('GET', '/api/users/1', 200, [
    'id' => 1,
    'name' => 'John Doe'
]);
```

### POST Stub with Body Matching
```php
$I->haveHttpStubFor('POST', '/api/users', 201, ['success' => true], [], [
    'bodyPatterns' => [
        ['equalToJson' => ['name' => 'Jane Doe']]
    ]
]);
```

### Verify Request Count
```php
$I->seeRequestCount(3, ['method' => 'GET', 'url' => '/api/data']);
```

### Debug Unmatched Requests
```php
$unmatched = $I->grabUnmatchedRequests();
codecept_debug($unmatched);
```

## Future Enhancements (Post-MVP)

- `haveDelayedStub()` - Simulate slow responses
- `haveMultipleStubs()` - Bulk import stubs
- `seeNoUnmatchedRequests()` - Assert all requests matched
- `grabNearMisses()` - Explicit near-miss analysis
- `grabStubMapping()` - Retrieve specific stub
- `sendFullReset()` - Public method for complete reset
- Scenario/state management support
- Request templating support
- File-based stub import helper

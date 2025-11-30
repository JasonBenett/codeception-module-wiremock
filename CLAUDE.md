# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Codeception module for integrating with WireMock, a tool for mocking HTTP services in tests. The module extends Codeception's base `Module` class to provide WireMock functionality to Codeception test suites.

**Key Details:**
- PHP 8.2+ required
- Codeception 5.3+ required
- PSR-18 (HTTP Client) and PSR-17 (HTTP Factories) required
- Guzzle HTTP client optional (auto-discovered if available)
- MIT licensed
- PSR-4 autoloading structure

**Architecture:**
- Depends on PSR-18/PSR-17 interfaces (true dependency inversion)
- No hard dependency on Guzzle - any PSR-compliant HTTP client works
- Optional auto-discovery creates Guzzle instances if available
- Users can inject their own PSR-18/PSR-17 implementations

## Project Structure

```
src/JasonBenett/CodeceptionModuleWiremock/
├── Module/
│   └── Wiremock.php                    # Main module class with all public methods
├── Exception/
│   ├── WiremockException.php           # Base exception
│   └── RequestVerificationException.php # Verification failure exception
└── Http/
    └── HttpClientException.php         # PSR-18 ClientExceptionInterface implementation

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
docker run -d -p 8080:8080 wiremock/wiremock:latest   # Start WireMock server on port 8080
docker ps | grep wiremock                             # Check if WireMock is running
docker logs <container-id>                            # View WireMock logs
docker stop <container-id>                            # Stop WireMock server
```

## Development Workflow & Conventions

### Semantic Commit Messages

**IMPORTANT:** This project uses [Conventional Commits](https://www.conventionalcommits.org/) specification for all commit messages.

**Format:**
```
<type>(<optional scope>): <description>

[optional body]

[optional footer(s)]
```

**Types:**
- `feat:` - New feature for users
- `fix:` - Bug fix for users
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting, missing semi colons, etc)
- `refactor:` - Code refactoring (neither fixes a bug nor adds a feature)
- `perf:` - Performance improvements
- `test:` - Adding or updating tests
- `chore:` - Changes to build process, CI, dependencies, etc

**Examples:**
```bash
# Feature addition
git commit -m "feat: add support for delayed stub responses"

# Bug fix
git commit -m "fix: handle empty response body in makeAdminRequest"

# Refactoring
git commit -m "refactor: extract HTTP client abstraction to use PSR-18"

# Documentation
git commit -m "docs: update README with PSR configuration examples"

# Breaking change
git commit -m "feat!: replace Guzzle with PSR-18 interfaces

BREAKING CHANGE: httpClient, requestFactory, and streamFactory are now required configuration options"
```

**All commits MUST:**
1. Follow the conventional commits format
2. Include the footer: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
3. Include: `Co-Authored-By: Claude <noreply@anthropic.com>`

### CHANGELOG Maintenance

**IMPORTANT:** The CHANGELOG.md file MUST be updated for every user-facing change.

**Format:** We follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Workflow:**
1. **During Development:** Add changes to the `[Unreleased]` section under appropriate category:
   - `### Added` - New features
   - `### Changed` - Changes in existing functionality
   - `### Deprecated` - Soon-to-be removed features
   - `### Removed` - Removed features
   - `### Fixed` - Bug fixes
   - `### Security` - Security fixes

2. **Before Release:** Move `[Unreleased]` changes to a new version section:
   ```markdown
   ## [1.1.0] - 2025-02-15

   ### Added
   - New feature description
   ```

3. **Update Guidelines:**
   - Write for users, not developers (focus on behavior, not implementation)
   - Be specific about what changed and why users care
   - Link to issues/PRs when relevant: `(#123)`
   - For breaking changes, explain migration path

**Example Entry:**
```markdown
## [Unreleased]

### Added
- Support for custom HTTP headers in all stub methods

### Changed
- `haveHttpStubFor()` now validates request matchers before sending to WireMock

### Fixed
- Near-miss analysis now handles special characters in URLs correctly
```

### Git Workflow

1. **Before Committing:**
   - Run all quality checks: `composer test && composer phpstan && composer cs-check`
   - Update CHANGELOG.md if user-facing changes
   - Verify all tests pass

2. **Committing:**
   - Use semantic commit message format
   - Include Claude Code footer

3. **Pull Requests:**
   - Ensure CI passes (all PHP versions, all checks)
   - Update README.md if API changes
   - Update CHANGELOG.md in Unreleased section

## Module Architecture

### Configuration Options

The module accepts the following configuration in `codeception.yml`:

```yaml
modules:
  enabled:
    - \JasonBenett\CodeceptionModuleWiremock\Module\Wiremock:
        host: localhost                  # Required: WireMock host (default: 127.0.0.1)
        port: 8080                       # Required: WireMock port (default: 8080)
        protocol: http                   # Optional: Protocol (default: http)
        cleanupBefore: test              # Optional: When to cleanup: 'never', 'test', or 'suite' (default: test)
        preserveFileMappings: true       # Optional: Keep file-based stubs on reset (default: true)
        adminPath: /__admin              # Optional: Admin API path (default: /__admin)
        httpClient: null                 # Optional: PSR-18 ClientInterface instance (auto-creates Guzzle if null)
        requestFactory: null             # Optional: PSR-17 RequestFactoryInterface instance (auto-creates if null)
        streamFactory: null              # Optional: PSR-17 StreamFactoryInterface instance (auto-creates if null)
```

**Note:** If PSR client instances are not provided, the module will automatically create Guzzle instances if guzzlehttp/guzzle is installed. For custom HTTP clients, provide PSR-18/PSR-17 implementations.

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

- `_initialize()` - Validates/creates PSR-18/PSR-17 clients and verifies WireMock connectivity
- `_beforeSuite()` - Cleanup if `cleanupBefore: suite`
- `_before()` - Cleanup if `cleanupBefore: test` (default behavior)

### Internal Methods

- `initHttpClient(): void` - Get PSR-18 client from config or auto-create Guzzle instance
- `initRequestFactory(): void` - Get PSR-17 request factory from config or auto-create
- `initStreamFactory(RequestFactoryInterface): void` - Get PSR-17 stream factory from config or auto-create
- `makeAdminRequest(string, string, array): array` - Make HTTP request to WireMock Admin API using PSR-18/PSR-17

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
   docker run -d -p 8080:8080 wiremock/wiremock:latest
   ```

2. Wait for WireMock to be ready:
   ```bash
   curl http://localhost:8080/__admin/health
   # Should return: {"status":"OK"}
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
- Check WireMock logs: `docker logs <container-id>`
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

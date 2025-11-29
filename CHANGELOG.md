# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-11-29

### Changed
- **BREAKING**: Replaced hard Guzzle dependency with PSR-18 (HTTP Client) and PSR-17 (HTTP Factories) interfaces
- Module now depends on PSR standards instead of concrete Guzzle implementation
- HTTP client architecture refactored to support any PSR-18/PSR-17 compliant implementation

### Added
- PSR-18 `ClientInterface` support for true dependency inversion
- PSR-17 `RequestFactoryInterface` and `StreamFactoryInterface` support
- Optional auto-discovery: automatically creates Guzzle instances if available (backward compatible)
- Support for any PSR-18/PSR-17 compliant HTTP client (Guzzle, Symfony HttpClient, custom implementations)
- New configuration options: `httpClient`, `requestFactory`, `streamFactory`
- Private initialization methods: `initHttpClient()`, `initRequestFactory()`, `initStreamFactory()`

### Removed
- Hard dependency on `guzzlehttp/guzzle` (now optional, moved to `require-dev`)

## [1.0.0] - 2025-11-28

### Added
- Initial release of Codeception WireMock integration module
- `haveHttpStubFor()` - Create HTTP stubs for any method with advanced request matching
- `seeHttpRequest()` - Verify HTTP requests were made with pattern matching
- `dontSeeHttpRequest()` - Verify HTTP requests were NOT made
- `seeRequestCount()` - Assert exact number of matching requests
- `grabRequestCount()` - Retrieve count of matching requests
- `grabAllRequests()` - Retrieve all recorded requests for debugging
- `grabUnmatchedRequests()` - Retrieve requests that didn't match any stub
- `sendReset()` - Reset WireMock to default state
- `sendClearRequests()` - Clear request journal without affecting stubs
- Automatic cleanup hooks (`cleanupBefore: test|suite|never`)
- Near-miss analysis for failed request verifications
- Support for advanced request matching (body patterns, headers, query parameters)
- WireMock health check on module initialization
- Comprehensive test coverage (15 unit tests, 11 functional tests)
- Guzzle HTTP client integration

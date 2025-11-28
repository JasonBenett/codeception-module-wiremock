# Contributing to Codeception Module WireMock

Thank you for your interest in contributing! This document provides guidelines and instructions for contributing to this project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Running Tests](#running-tests)
- [Code Quality](#code-quality)
- [Submitting Changes](#submitting-changes)
- [Commit Message Guidelines](#commit-message-guidelines)

## Code of Conduct

This project adheres to a code of conduct that all contributors are expected to follow:

- Be respectful and inclusive
- Welcome newcomers and help them learn
- Focus on constructive feedback
- Assume good intentions

## Getting Started

### Prerequisites

- PHP 8.2 or higher
- Composer
- Docker and Docker Compose (for running WireMock)
- Git

### Fork and Clone

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/codeception-module-wiremock.git
   cd codeception-module-wiremock
   ```

3. Add the upstream repository:
   ```bash
   git remote add upstream https://github.com/jasonbenett/codeception-module-wiremock.git
   ```

### Install Dependencies

```bash
composer install
```

### Start WireMock Server

```bash
docker-compose up -d
```

Verify WireMock is running:
```bash
curl http://localhost:8080/__admin/health
```

## Development Workflow

1. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following the coding standards

3. **Write or update tests** for your changes

4. **Run the test suite** to ensure everything passes

5. **Commit your changes** with a clear commit message

6. **Push to your fork** and create a pull request

## Running Tests

### Unit Tests

Unit tests don't require WireMock to be running:

```bash
composer test
```

### Functional Tests

Functional tests require WireMock to be running:

```bash
docker-compose up -d
composer test:functional
```

### Run All Tests

```bash
composer test && composer test:functional
```

## Code Quality

This project enforces strict code quality standards. All contributions must pass these checks:

### Static Analysis (PHPStan)

PHPStan runs at max level and must have zero errors:

```bash
composer phpstan
```

### Code Style (PER Coding Style 3.0)

The project follows [PER Coding Style 3.0](https://www.php-fig.org/per/coding-style/):

Check for violations:
```bash
composer cs-check
```

Automatically fix violations:
```bash
composer cs-fix
```

### Run All Quality Checks

Before submitting a pull request, ensure all checks pass:

```bash
composer phpstan && composer cs-check && composer test && composer test:functional
```

## Submitting Changes

### Pull Request Process

1. **Update documentation** if you've changed functionality
2. **Add tests** for new features or bug fixes
3. **Ensure all tests pass** and code quality checks succeed
4. **Update CLAUDE.md** if you've added new methods or changed architecture
5. **Create a pull request** with a clear description of changes

### Pull Request Description

Your pull request should include:

- **Summary**: What does this PR do?
- **Motivation**: Why is this change needed?
- **Implementation**: How does it work?
- **Testing**: How did you test this?
- **Breaking Changes**: Does this break backward compatibility?

### Example PR Description

```markdown
## Summary
Add support for delayed responses in stub creation

## Motivation
Users need to simulate slow network responses for testing timeout handling

## Implementation
- Added `haveDelayedStub()` method to Wiremock module
- Implemented `fixedDelayMilliseconds` parameter in WireMock API calls
- Updated request matching to support delay patterns

## Testing
- Added unit tests for delay parameter handling
- Added functional test verifying actual delay behavior
- All existing tests pass

## Breaking Changes
None - this is backward compatible
```

## Commit Message Guidelines

This project uses [Semantic Commit Messages](https://www.conventionalcommits.org/):

### Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only changes
- `style`: Code style changes (formatting, missing semi-colons, etc.)
- `refactor`: Code change that neither fixes a bug nor adds a feature
- `perf`: Performance improvement
- `test`: Adding or updating tests
- `chore`: Changes to build process or auxiliary tools

### Examples

```bash
# Feature
feat: add support for response delays

# Bug fix
fix: handle null response body in makeAdminRequest

# Documentation
docs: update README with delay examples

# Tests
test: add coverage for edge cases in request matching

# Refactoring
refactor: simplify near-miss formatting logic
```

### Commit Message Rules

1. Use imperative mood ("add" not "added" or "adds")
2. Don't capitalize first letter
3. No period at the end of subject
4. Keep subject line under 50 characters
5. Separate subject from body with blank line
6. Wrap body at 72 characters
7. Use body to explain *what* and *why*, not *how*

## Code Style Guidelines

### PHPDoc Comments

All public methods must have comprehensive PHPDoc:

```php
/**
 * Create an HTTP stub for any HTTP method
 *
 * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
 * @param string $url URL or URL pattern to match
 * @param array<string, mixed> $requestMatchers Additional matching criteria
 *
 * @return string UUID of created stub mapping
 *
 * @throws WiremockException If stub creation fails
 * @throws JsonException If JSON encoding fails
 */
public function haveHttpStubFor(string $method, string $url, ...): string
```

### Type Safety

- Use strict types: `declare(strict_types=1);`
- Add type hints for all parameters and return types
- Use PHPStan type annotations for arrays
- Validate mixed types before casting

### Naming Conventions

Follow Codeception naming patterns:

- `have*` - Setup/fixture methods
- `see*` / `dontSee*` - Assertion methods
- `grab*` - Data retrieval methods
- `send*` - Direct action methods

## Testing Guidelines

### Test Organization

- **Unit tests**: Mock external dependencies, test logic in isolation
- **Functional tests**: Test against real WireMock server

### Writing Good Tests

```php
public function testDescriptiveMethodName(FunctionalTester $I): void
{
    // Arrange - Set up test data and stubs
    $I->haveHttpStubFor('GET', '/api/test', 200, 'response');

    // Act - Perform the action
    $response = file_get_contents('http://localhost:8080/api/test');

    // Assert - Verify the results
    $I->assertEquals('response', $response);
    $I->seeHttpRequest('GET', '/api/test');
}
```

### Test Coverage

- All new methods must have tests
- Aim for edge cases, not just happy paths
- Test error conditions and exceptions

## Questions or Problems?

- **Bugs**: Open an issue with reproduction steps
- **Features**: Open an issue to discuss before implementing
- **Questions**: Open a discussion on GitHub

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## Recognition

Contributors will be recognized in:
- GitHub contributors list
- Release notes for significant contributions

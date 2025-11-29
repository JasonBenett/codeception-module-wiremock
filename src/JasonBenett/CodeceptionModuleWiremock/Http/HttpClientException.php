<?php

declare(strict_types=1);

namespace JasonBenett\CodeceptionModuleWiremock\Http;

use JasonBenett\CodeceptionModuleWiremock\Exception\WiremockException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Exception thrown when HTTP client operations fail
 *
 * Implements PSR-18 ClientExceptionInterface for compatibility
 */
class HttpClientException extends WiremockException implements ClientExceptionInterface {}

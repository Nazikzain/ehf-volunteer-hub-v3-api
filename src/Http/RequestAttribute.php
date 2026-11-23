<?php

declare(strict_types=1);

namespace Ehf\Api\Http;

/**
 * Names of PSR-7 request attributes set by the middleware pipeline.
 */
final class RequestAttribute
{
    public const string REQUEST_ID = 'ehf.request_id';

    private function __construct() {}
}

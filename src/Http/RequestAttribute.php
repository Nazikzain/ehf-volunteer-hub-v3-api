<?php

declare(strict_types=1);

namespace Ehf\Api\Http;

/**
 * Names of PSR-7 request attributes set by the middleware pipeline.
 */
final class RequestAttribute
{
    public const string REQUEST_ID = 'ehf.request_id';

    /** The signed-in Principal, or absent. */
    public const string PRINCIPAL = 'ehf.principal';

    /** 'none', 'valid' or 'expired' — whether a session cookie was sent and what became of it. */
    public const string SESSION_STATE = 'ehf.session_state';

    /** The raw session identifier from the cookie (never logged). */
    public const string SESSION_ID = 'ehf.session_id';

    private function __construct() {}
}

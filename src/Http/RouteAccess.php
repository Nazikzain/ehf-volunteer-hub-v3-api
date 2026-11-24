<?php

declare(strict_types=1);

namespace Ehf\Api\Http;

/**
 * Route arguments that declare access (API §2.11: each route has exactly one primary permission
 * code) and per-route controls. Set in config/routes.php; mirrored by OpenAPI `x-permission`.
 */
final class RouteAccess
{
    public const string PERMISSION = 'ehf_permission';

    public const string AUDIT = 'ehf_audit';

    public const string RATE_LIMIT = 'ehf_rate_limit';

    public const string IDEMPOTENT = 'ehf_idempotent';

    /** No sign-in needed. */
    public const string PUBLIC = 'public';

    /** Any signed-in user. */
    public const string SIGNED_IN = 'signed_in';

    /** Signed in; the service decides the code from the record (e.g. an audit entry's category). */
    public const string SERVICE_CHECKED = 'service_checked';

    public const string RATE_LIMIT_TOKEN = 'token';

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace Ehf\Api\Audit;

use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\RequestAttribute;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who acted and from where, for audit records (API §11): actor, acting role, request ID and a
 * shortened IP address.
 */
final readonly class AuditContext
{
    public function __construct(
        public ?int $actorUserId,
        public ?string $actingRole,
        public ?string $requestId,
        public ?string $ipPrefix,
    ) {}

    public static function fromRequest(ServerRequestInterface $request, ?string $actingRole = null): self
    {
        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);
        $principal = $principal instanceof Principal ? $principal : null;
        $requestId = $request->getAttribute(RequestAttribute::REQUEST_ID);

        return new self(
            $principal?->userId,
            $actingRole ?? $principal?->actingRole(),
            \is_string($requestId) ? $requestId : null,
            self::ipPrefix($request),
        );
    }

    public function withActor(int $userId, ?string $actingRole = null): self
    {
        return new self($userId, $actingRole, $this->requestId, $this->ipPrefix);
    }

    /**
     * IPv4 → first three octets (/24); IPv6 → first three groups (/48). Never the full address.
     * Only REMOTE_ADDR is used: forwarded headers are not trusted until the host's proxy setup is
     * known (hosting checks).
     */
    public static function ipPrefix(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!\is_string($ip) || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = (string) inet_pton($ip);
            $prefix = inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));

            return ($prefix === false ? '::' : $prefix) . '/48';
        }

        return null;
    }
}

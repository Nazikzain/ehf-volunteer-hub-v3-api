<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Integration;

use Ehf\Api\Tests\Support\IntegrationTestCase;
use Ehf\Api\Tests\Support\TestApp;
use Ehf\Api\Tests\Support\TestSession;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every endpoint (Phases 2–5) × every persona, against API V1.3.1 Appendix A (transcribed here, not
 * derived from the seeded bundles, so a wrong bundle fails this test).
 *
 * - Anonymous: public endpoints answer; every other endpoint is `401 unauthenticated`.
 * - A persona without the endpoint's code is stopped at the route (`403 forbidden`).
 * - A persona with the code gets past the route check (any outcome other than 401/403 forbidden;
 *   requests use empty bodies and unknown ids, so they change nothing).
 */
final class PermissionMatrixTest extends IntegrationTestCase
{
    private const array PERSONAS = ['volunteer', 'full_admin', 'monitoring_admin', 'technical_manager', 'owner'];

    private const string PUBLIC = 'public';

    private const string SIGNED_IN = 'signed_in';

    private const array TM = ['technical_manager'];

    private const array SO = ['owner'];

    private const array FA = ['full_admin'];

    private const array V = ['volunteer'];

    /** @var array<string, TestSession> */
    private static array $sessions = [];

    /**
     * method, path, allowed personas (or PUBLIC / SIGNED_IN).
     *
     * @return array<string, array{string, string, string|list<string>}>
     */
    public static function matrix(): array
    {
        return [
            'health' => ['GET', '/api/v3/health', self::PUBLIC],
            'session' => ['GET', '/api/v3/auth/session', self::PUBLIC],
            'login' => ['POST', '/api/v3/auth/login', self::PUBLIC],
            'inspect invitation' => ['POST', '/api/v3/auth/invitations/inspect', self::PUBLIC],
            'activate' => ['POST', '/api/v3/auth/activate', self::PUBLIC],
            'inspect reset' => ['POST', '/api/v3/auth/password/reset/inspect', self::PUBLIC],
            'reset' => ['POST', '/api/v3/auth/password/reset', self::PUBLIC],
            'logout' => ['POST', '/api/v3/auth/logout', self::SIGNED_IN],
            'change password' => ['POST', '/api/v3/auth/password/change', self::SIGNED_IN],
            'system health' => ['GET', '/api/v3/system/health', self::TM],
            'list users' => ['GET', '/api/v3/users', self::TM],
            'create user' => ['POST', '/api/v3/users', self::TM],
            'show user' => ['GET', '/api/v3/users/{target}', self::TM],
            'edit user' => ['PATCH', '/api/v3/users/{target}', self::TM],
            'cancel invitation' => ['DELETE', '/api/v3/users/{target}/invitation', self::TM],
            'suspend' => ['POST', '/api/v3/users/{target}/suspend', self::TM],
            'reactivate' => ['POST', '/api/v3/users/{target}/reactivate', self::TM],
            'deactivate' => ['POST', '/api/v3/users/{target}/deactivate', self::TM],
            'user roles' => ['GET', '/api/v3/users/{target}/roles', self::TM],
            'assign role' => ['POST', '/api/v3/users/{target}/roles', self::TM],
            'remove role' => ['DELETE', '/api/v3/users/{target}/roles/999999', self::TM],
            'user grants' => ['GET', '/api/v3/users/{target}/grants', self::TM],
            'create grant' => ['POST', '/api/v3/users/{target}/grants', self::TM],
            'revoke grant' => ['DELETE', '/api/v3/users/{target}/grants/999999', self::TM],
            'role catalogue' => ['GET', '/api/v3/roles', self::TM],
            'permission catalogue' => ['GET', '/api/v3/permissions', self::TM],
            'sessions' => ['GET', '/api/v3/sessions', self::TM],
            'revoke session' => ['DELETE', '/api/v3/sessions/' . 'a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0', self::TM],
            'revoke all sessions' => ['POST', '/api/v3/users/{target}/sessions/revoke-all', self::TM],
            'owners' => ['GET', '/api/v3/system-owners', self::SO],
            'remove owner' => ['DELETE', '/api/v3/system-owners/{target}', self::SO],
            'grant technical manager' => ['POST', '/api/v3/system-owners/technical-manager-assignments', self::SO],
            'revoke technical manager' => ['DELETE', '/api/v3/system-owners/technical-manager-assignments/999999', self::SO],
            'exceptions' => ['GET', '/api/v3/operational-exceptions', self::SO],
            'create exception' => ['POST', '/api/v3/operational-exceptions', self::SO],
            'authorise exception' => ['POST', '/api/v3/operational-exceptions/999999/authorise', self::SO],
            'reject exception' => ['POST', '/api/v3/operational-exceptions/999999/reject', self::SO],
            'revoke exception' => ['POST', '/api/v3/operational-exceptions/999999/revoke', self::SO],
            'provisioning candidates' => ['GET', '/api/v3/portal-provisioning/candidates', self::TM],
            'provision portal account' => ['POST', '/api/v3/volunteers/999999/portal-account', self::TM],
            'own profile' => ['GET', '/api/v3/me/profile', self::V],
            'edit own profile' => ['PATCH', '/api/v3/me/profile', self::V],
            'browse volunteers' => ['GET', '/api/v3/volunteers', self::FA],
            'create volunteer' => ['POST', '/api/v3/volunteers', self::FA],
            'show volunteer' => ['GET', '/api/v3/volunteers/999999', self::FA],
            'edit volunteer' => ['PATCH', '/api/v3/volunteers/999999', self::FA],
            'accept volunteer' => ['POST', '/api/v3/volunteers/999999/accept', self::FA],
            'change volunteer status' => ['POST', '/api/v3/volunteers/999999/status-changes', self::FA],
            'volunteer status history' => ['GET', '/api/v3/volunteers/999999/status-history', self::FA],
            'volunteer notes (R6)' => ['GET', '/api/v3/volunteers/999999/notes', self::FA],
            'add volunteer note (R6)' => ['POST', '/api/v3/volunteers/999999/notes', self::FA],
            'security audit' => ['GET', '/api/v3/audit-logs/security', self::TM],
            'operational audit (R10: nobody)' => ['GET', '/api/v3/audit-logs/operational', []],
            'verify audit' => ['GET', '/api/v3/audit-logs/verify', self::TM],
            'audit entry (category-checked)' => ['GET', '/api/v3/audit-logs/999999', self::SIGNED_IN],
        ];
    }

    public function testTheMatrixCoversEveryRoute(): void
    {
        $app = self::$app;
        self::assertNotNull($app);
        $routes = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes[] = $method . ' ' . preg_replace('/\{[a-zA-Z]+:(?:[^{}]|\{\d+\})+\}/', '{}', $route->getPattern());
            }
        }

        $covered = [];
        foreach (self::matrix() as [$method, $path]) {
            $covered[] = $method . ' ' . preg_replace(['/\{target\}/', '/\/(999999|a0[a0]+)(?=\/|$)/'], ['{}', '/{}'], $path);
        }

        sort($routes);
        sort($covered);
        self::assertSame($routes, $covered);
    }

    /**
     * @param string|list<string> $allowed
     */
    #[DataProvider('matrix')]
    public function testAccess(string $method, string $path, string|array $allowed): void
    {
        $path = str_replace('{target}', (string) self::$users['target'], $path);
        $body = $method === 'GET' ? null : [];

        $anonymous = $this->call($method, $path, null, $body);
        if ($allowed === self::PUBLIC) {
            self::assertNotSame(401, $anonymous->getStatusCode(), "anonymous {$method} {$path}");
            self::assertNotSame(403, $anonymous->getStatusCode(), "anonymous {$method} {$path}");

            return;
        }
        self::assertSame(401, $anonymous->getStatusCode(), "anonymous {$method} {$path}");
        self::assertSame('unauthenticated', self::errorCode($anonymous));

        foreach (self::PERSONAS as $persona) {
            $response = $this->call($method, $path, $this->session($persona), $body);
            $code = $response->getStatusCode() >= 400 ? TestApp::error($response)['code'] : null;
            $mayPass = $allowed === self::SIGNED_IN || (\is_array($allowed) && \in_array($persona, $allowed, true));

            if ($mayPass) {
                self::assertNotSame(401, $response->getStatusCode(), "{$persona} {$method} {$path}: " . $response->getBody());
                self::assertNotSame('forbidden', $code, "{$persona} {$method} {$path} was refused at the route");
            } else {
                self::assertSame(403, $response->getStatusCode(), "{$persona} {$method} {$path}: " . $response->getBody());
                self::assertSame('forbidden', $code, "{$persona} {$method} {$path}");
            }

            if (\in_array($path, ['/api/v3/auth/logout'], true)) {
                unset(self::$sessions[$persona]); // that request ended the session
            }
        }
    }

    public function testRefusalsAtTheRouteAreAudited(): void
    {
        $before = (int) self::db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'role.technical_manager.granted' AND outcome = 'denied'");
        $response = $this->call('POST', '/api/v3/system-owners/technical-manager-assignments', $this->session('technical_manager'), []);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before + 1, (int) self::db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'role.technical_manager.granted' AND outcome = 'denied'"));
    }

    private function session(string $persona): TestSession
    {
        return self::$sessions[$persona] ??= $this->login($persona);
    }
}

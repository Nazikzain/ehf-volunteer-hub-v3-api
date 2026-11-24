<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

use Ehf\Api\Auth\AuthService;
use Ehf\Api\Auth\SessionCookie;
use Ehf\Api\Bootstrap\AppFactory;
use Ehf\Api\Config\AppEnvironment;
use Ehf\Api\Config\AuthSettings;
use Ehf\Api\Config\DatabaseSettings;
use Ehf\Api\Config\Settings;
use Ehf\Api\Infrastructure\Time\Clock;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * End-to-end tests through the real application, against a disposable migrated database
 * (DB_TEST_*). Each test class gets its own database and a fixed set of personas.
 *
 * The sign-in values below are TEST-ONLY. They are not decisions: A3, A4, A6 and A7 remain
 * pending, and nothing outside the tests uses these numbers.
 */
abstract class IntegrationTestCase extends TestCase
{
    public const string PASSWORD = 'correct-horse-battery-test';

    public const string ORIGIN = 'https://app.example.test';

    protected static ?TestDatabase $db = null;

    protected static MutableClock $clock;

    /** @var App<ContainerInterface>|null */
    protected static ?App $app = null;

    /** @var array<string, int> persona => user id */
    protected static array $users = [];

    protected static int $ipCounter = 0;

    public static function setUpBeforeClass(): void
    {
        self::$db = TestDatabase::create();
        if (self::$db === null) {
            return;
        }

        self::$db->migrate();
        self::$clock = new MutableClock();
        self::$app = self::buildApp(self::authSettingsForTests());
        self::$users = self::personas();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->drop();
        self::$db = null;
        self::$app = null;
        self::$users = [];
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('DB_TEST_* not set: integration tests need a throwaway MySQL or MariaDB server.');
        }
    }

    public static function authSettingsForTests(): AuthSettings
    {
        return new AuthSettings(
            sameSite: 'Lax',
            sessionIdleMinutes: 30,
            sessionAbsoluteMinutes: 720,
            passwordMinLength: 12,
            passwordMaxLength: 128,
            loginAccountMax: 5,
            loginIpMax: 20,
            loginWindowMinutes: 15,
            tokenIpMax: 10,
            tokenWindowMinutes: 15,
        );
    }

    /**
     * @return App<ContainerInterface>
     */
    protected static function buildApp(?AuthSettings $auth): App
    {
        $server = TestDatabase::settings();
        self::assertNotNull($server);
        self::assertNotNull(self::$db);
        $database = new DatabaseSettings($server->host, $server->port, self::$db->name, $server->user, $server->password());

        $settings = new Settings(
            environment: AppEnvironment::Testing,
            projectRoot: \dirname(__DIR__, 2),
            basePath: '',
            corsAllowedOrigins: [self::ORIGIN],
            hstsEnabled: false,
            maxJsonBodyBytes: Settings::DEFAULT_MAX_JSON_BODY_BYTES,
            logPath: 'php://memory',
            logLevel: 'debug',
            database: $database,
            auth: $auth,
        );

        return AppFactory::create($settings, new Logger('test', [new TestHandler()]), [Clock::class => self::$clock]);
    }

    /**
     * Fixed personas: a System Owner (bootstrap membership, no role), a second owner, a Technical
     * Manager (granted through an owner authorisation), a Full Admin, a Monitoring Admin, a
     * Volunteer, and two accounts without roles to act on.
     *
     * @return array<string, int>
     */
    protected static function personas(): array
    {
        $u = [];
        foreach (['owner', 'owner2', 'technical_manager', 'full_admin', 'monitoring_admin', 'volunteer', 'target', 'target2'] as $name) {
            $u[$name] = self::createUser($name . '@example.test', ucfirst(str_replace('_', ' ', $name)));
        }

        $db = self::db();
        $mA = $db->insert("INSERT INTO system_owner_memberships (user_id, added_via, bootstrap_key, add_reason, active_key) VALUES (?, 'bootstrap', 1, 'Test bootstrap', ?)", [$u['owner'], $u['owner']]);
        $db->insert("INSERT INTO system_owner_memberships (user_id, added_via, added_by_membership_id, added_by_user_id, add_reason, active_key) VALUES (?, 'owner', ?, ?, 'Second owner', ?)", [$u['owner2'], $mA, $u['owner'], $u['owner2']]);
        self::grantTechnicalManager($u['technical_manager'], $mA, $u['owner']);
        foreach (['full_admin', 'monitoring_admin', 'volunteer'] as $role) {
            self::assignRole($u[$role], $role, $u['technical_manager']);
        }

        return $u;
    }

    protected static function createUser(string $email, string $name, string $status = 'active', ?string $password = self::PASSWORD): int
    {
        return self::db()->insert(
            'INSERT INTO users (email, display_name, status, password_hash) VALUES (?, ?, ?, ?)',
            [$email, $name, $status, $password === null ? null : password_hash($password, PASSWORD_ARGON2ID)],
        );
    }

    protected static function grantTechnicalManager(int $userId, int $membershipId, int $ownerUserId): int
    {
        $db = self::db();
        $authorisation = $db->insert(
            "INSERT INTO owner_authorisations (kind, owner_membership_id, owner_user_id, subject_user_id, action, reason) VALUES ('owner', ?, ?, ?, 'grant_technical_manager', 'Test fixture')",
            [$membershipId, $ownerUserId, $userId],
        );

        return $db->insert(
            "INSERT INTO user_roles (user_id, role_code, reason, granted_by_user_id, owner_authorisation_id, active_key) VALUES (?, 'technical_manager', 'Test fixture', ?, ?, ?)",
            [$userId, $ownerUserId, $authorisation, "{$userId}:technical_manager:organisation"],
        );
    }

    protected static function assignRole(int $userId, string $role, int $grantedBy): int
    {
        return self::db()->insert(
            'INSERT INTO user_roles (user_id, role_code, reason, granted_by_user_id, active_key) VALUES (?, ?, ?, ?, ?)',
            [$userId, $role, 'Test fixture', $grantedBy, "{$userId}:{$role}:organisation"],
        );
    }

    protected static function membershipId(int $userId): int
    {
        return (int) self::db()->scalar('SELECT id FROM system_owner_memberships WHERE user_id = ? AND removed_at IS NULL', $userId);
    }

    /**
     * Inserts a single-use token and returns the raw value (only its hash is stored).
     */
    protected static function token(int $userId, string $type, string $expiresIn = '+3 days'): string
    {
        $token = bin2hex(random_bytes(24));
        self::db()->insert(
            'INSERT INTO auth_tokens (user_id, type, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, AuthService::hashToken($token), self::$clock->now()->format('Y-m-d H:i:s'), self::$clock->now()->modify($expiresIn)->format('Y-m-d H:i:s')],
        );

        return $token;
    }

    /**
     * Signs in through POST /auth/login.
     */
    protected function login(string $persona, ?string $email = null, string $password = self::PASSWORD): TestSession
    {
        $response = $this->call('POST', '/api/v3/auth/login', null, ['email' => $email ?? "{$persona}@example.test", 'password' => $password]);
        self::assertSame(200, $response->getStatusCode(), 'Sign-in failed: ' . $response->getBody());

        return TestSession::fromResponse($response);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function call(string $method, string $path, ?TestSession $session, ?array $body = null, array $headers = [], ?string $ip = null): ResponseInterface
    {
        $app = self::$app;
        self::assertNotNull($app);

        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => $ip ?? '203.0.113.' . (++self::$ipCounter % 250 + 1)]);
        $unsafe = !\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($unsafe) {
            $request = $request->withHeader('Origin', self::ORIGIN);
        }
        if ($session !== null) {
            $request = $request->withCookieParams([SessionCookie::NAME => $session->id]);
            if ($unsafe) {
                $request = $request->withHeader('X-CSRF-Token', $session->csrf);
            }
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)));
        }
        foreach ($headers as $name => $value) {
            $request = $value === '' ? $request->withoutHeader($name) : $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function data(ResponseInterface $response): array
    {
        $json = TestApp::json($response);
        $data = $json['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function items(ResponseInterface $response): array
    {
        $data = TestApp::json($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());
        self::assertTrue(array_is_list($data));
        $items = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    protected static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    protected static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function map(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The effective permissions of a session, from GET /auth/session.
     *
     * @return array<array-key, mixed>
     */
    protected function permissions(TestSession $session): array
    {
        return self::map(self::data($this->call('GET', '/api/v3/auth/session', $session))['permissions']);
    }

    protected static function errorCode(ResponseInterface $response): string
    {
        return TestApp::error($response)['code'];
    }

    protected static function db(): TestDatabase
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }

    protected static function auditCount(string $action, string $outcome): int
    {
        return (int) self::db()->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND outcome = ?', $action, $outcome);
    }
}

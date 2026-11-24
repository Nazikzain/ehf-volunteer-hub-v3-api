<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Auth\AuthService;
use Ehf\Api\Auth\SessionCookie;
use Ehf\Api\Auth\SessionService;
use Ehf\Api\Authorisation\PermissionResolver;
use Ehf\Api\Authorisation\Principal;
use Ehf\Api\Http\RequestAttribute;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.1 — sessions, sign-in, sign-out, password change, invitation activation and password
 * reset. Sending invitations and reset emails is not available (A5, A11 pending).
 */
final readonly class AuthController
{
    use ControllerSupport;

    public function __construct(
        private AuthService $auth,
        private SessionService $sessions,
        private SessionCookie $cookie,
        private JsonResponder $responder,
    ) {}

    public function session(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute(RequestAttribute::PRINCIPAL);

        return $this->responder->success($request, self::sessionView($principal instanceof Principal ? $principal : null));
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['email', 'password']);
        $email = $input->string('email', 1, 254);
        $password = $input->string('password', 1, 1024, false);
        $input->validate();

        $sessionId = $this->auth->login($email, $password, self::ip($request), self::agent($request), AuditContext::fromRequest($request));

        return $this->signedIn($request, $sessionId);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout(self::principal($request), AuditContext::fromRequest($request));

        return $this->cookie->clear($this->responder->noContent());
    }

    public function changePassword(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['current_password', 'new_password']);
        $current = $input->string('current_password', 1, 1024, false);
        $new = $input->string('new_password', 1, 1024, false);
        $input->validate();

        $this->auth->changePassword(self::principal($request), $current, $new, AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    public function inspectInvitation(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->auth->inspectInvitation($this->token($request)));
    }

    public function activate(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['token', 'password', 'password_confirmation']);
        $token = $input->string('token', 20, 200);
        $password = $input->string('password', 1, 1024, false);
        $confirmation = $input->string('password_confirmation', 1, 1024, false);
        $input->validate();

        $sessionId = $this->auth->activate($token, $password, $confirmation, self::agent($request), AuditContext::fromRequest($request));

        return $this->signedIn($request, $sessionId);
    }

    public function inspectReset(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->auth->inspectReset($this->token($request)));
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $input = RequestInput::body($request, ['token', 'password', 'password_confirmation']);
        $token = $input->string('token', 20, 200);
        $password = $input->string('password', 1, 1024, false);
        $confirmation = $input->string('password_confirmation', 1, 1024, false);
        $input->validate();

        $this->auth->resetPassword($token, $password, $confirmation, AuditContext::fromRequest($request));

        return $this->responder->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    public static function sessionView(?Principal $principal): array
    {
        if ($principal === null) {
            return ['authenticated' => false, 'user' => null, 'roles' => [], 'permissions' => new \stdClass(), 'field_access' => new \stdClass(), 'volunteer_id' => null, 'csrf_token' => null];
        }

        return [
            'authenticated' => true,
            'user' => ['id' => $principal->userId, 'display_name' => $principal->displayName, 'email' => $principal->email],
            'roles' => $principal->roles,
            'permissions' => $principal->permissions === [] ? new \stdClass() : $principal->permissions,
            'field_access' => PermissionResolver::fieldAccess($principal->permissions),
            'volunteer_id' => $principal->volunteerId,
            'csrf_token' => $principal->csrfToken,
        ];
    }

    private function signedIn(ServerRequestInterface $request, string $sessionId): ResponseInterface
    {
        $principal = $this->sessions->resolve($sessionId);

        return $this->cookie->set($this->responder->success($request, self::sessionView($principal)), $sessionId);
    }

    private function token(ServerRequestInterface $request): string
    {
        $input = RequestInput::body($request, ['token']);
        $token = $input->string('token', 20, 200);
        $input->validate();

        return $token;
    }
}

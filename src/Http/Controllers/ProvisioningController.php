<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\ProvisioningService;
use Ehf\Api\Validation\RequestInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.2 — portal account provisioning (Technical Manager; account data only).
 */
final readonly class ProvisioningController
{
    use ControllerSupport;

    public function __construct(
        private ProvisioningService $provisioning,
        private JsonResponder $responder,
    ) {}

    public function candidates(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['q', 'page', 'per_page']);
        $q = $query->optionalString('q', 1, 100);
        [$page, $perPage] = self::paging($query);
        $query->validate();

        $result = $this->provisioning->candidates($q, $page, $perPage);

        return $this->responder->success($request, $result['items'], 200, self::pageMeta($page, $perPage, $result['total']));
    }

    /**
     * @param array<string, string> $args
     */
    public function provision(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['invitation_email', 'send_invitation']);
        $email = $input->has('invitation_email') ? $input->email('invitation_email') : null;
        $send = $input->raw('send_invitation') ?? true;
        if (!\is_bool($send)) {
            $input->error('send_invitation', 'type', 'Must be true or false.');
        }
        $input->validate();

        $result = $this->provisioning->provision(self::principal($request), self::id($args, 'id'), $email, $send === true, AuditContext::fromRequest($request));

        return $this->responder->success($request, $result, 201);
    }
}

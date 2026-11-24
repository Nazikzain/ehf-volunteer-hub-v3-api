<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Authorisation\FieldPolicy;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Services\VolunteerService;
use Ehf\Api\Validation\RequestInput;
use Ehf\Api\Validation\VolunteerInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.3 — the volunteer's own profile. The record comes only from the session; no volunteer id
 * is accepted. Responses never contain notes.
 */
final readonly class ProfileController
{
    use ControllerSupport;

    /** Volunteer record fields that exist but are never editable here (`422 field_not_editable`). */
    private const array READ_ONLY = ['id', 'reference', 'status', 'accepted_at', 'created_at', 'updated_at'];

    public function __construct(
        private VolunteerService $volunteers,
        private JsonResponder $responder,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->success($request, $this->volunteers->ownProfile(self::principal($request)));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $fields = array_keys(FieldPolicy::VOLUNTEER_FIELDS);
        $input = RequestInput::body($request, [...$fields, ...self::READ_ONLY, 'version']);
        $sent = array_values(array_intersect([...$fields, ...self::READ_ONLY], $input->sentFields()));
        $editable = array_values(array_intersect($sent, FieldPolicy::SELF_EDITABLE));
        $principal = self::principal($request);
        $this->volunteers->assertSelfEditable($principal, $sent);
        $values = VolunteerInput::values($input, $editable);
        $version = $input->int('version');
        $input->validate();

        return $this->responder->success($request, $this->volunteers->updateOwnProfile($principal, $editable, $values, $version, AuditContext::fromRequest($request)));
    }
}

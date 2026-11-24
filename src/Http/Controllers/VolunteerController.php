<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Controllers;

use Ehf\Api\Audit\AuditContext;
use Ehf\Api\Authorisation\FieldPolicy;
use Ehf\Api\Http\Response\JsonResponder;
use Ehf\Api\Repositories\VolunteerRepository;
use Ehf\Api\Services\VolunteerService;
use Ehf\Api\Validation\RequestInput;
use Ehf\Api\Validation\VolunteerInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API §7.4 — volunteer operational records and confidential volunteer notes.
 */
final readonly class VolunteerController
{
    use ControllerSupport;

    public function __construct(
        private VolunteerService $volunteers,
        private JsonResponder $responder,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = RequestInput::query($request, ['q', 'status', 'project_id', 'sort', 'page', 'per_page']);
        $q = $query->optionalString('q', 1, 100);
        $status = $query->oneOf('status', VolunteerService::STATUSES, false);
        $projectId = $query->optionalInt('project_id');
        $sort = $query->oneOf('sort', array_keys(VolunteerRepository::SORTS), false) ?? 'full_name';
        [$page, $perPage] = self::paging($query);
        $query->validate();

        $result = $this->volunteers->search(self::principal($request), $q, $status, $projectId, $sort, $page, $perPage);

        return $this->responder->success($request, $result['items'], 200, self::pageMeta($page, $perPage, $result['total']) + ['field_sets' => $result['field_sets']]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->record($request, $this->volunteers->get(self::principal($request), self::id($args, 'id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = self::principal($request);
        $context = AuditContext::fromRequest($request);
        $fields = array_keys(FieldPolicy::VOLUNTEER_FIELDS);
        $input = RequestInput::body($request, $fields);
        $sent = array_values(array_intersect($fields, $input->sentFields()));
        $this->volunteers->assertWritable($principal, $sent, 'volunteer.created', null, $context);
        if (!\in_array('full_name', $sent, true)) {
            $input->error('full_name', 'required', 'This field is required.');
        }
        $values = VolunteerInput::values($input, $sent);
        $input->validate();

        return $this->record($request, $this->volunteers->create($principal, $sent, $values, $context), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = self::id($args, 'id');
        $principal = self::principal($request);
        $context = AuditContext::fromRequest($request);
        $fields = array_keys(FieldPolicy::VOLUNTEER_FIELDS);
        $input = RequestInput::body($request, [...$fields, 'version']);
        $sent = array_values(array_intersect($fields, $input->sentFields()));
        $this->volunteers->assertWritable($principal, $sent, 'volunteer.updated', $id, $context);
        $values = VolunteerInput::values($input, $sent);
        $version = $input->int('version');
        $input->validate();

        return $this->record($request, $this->volunteers->update($principal, $id, $values, $version, $context));
    }

    /**
     * @param array<string, string> $args
     */
    public function accept(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['note', 'version']);
        $note = $input->optionalString('note', 1, 500);
        $version = $input->int('version');
        $input->validate();

        return $this->record($request, $this->volunteers->accept(self::principal($request), self::id($args, 'id'), $note, $version, AuditContext::fromRequest($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function changeStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['to_status', 'reason', 'version']);
        $to = $input->oneOf('to_status', VolunteerService::STATUSES);
        $reason = $input->string('reason', 1, 500);
        $version = $input->int('version');
        $input->validate();

        return $this->record($request, $this->volunteers->changeStatus(self::principal($request), self::id($args, 'id'), (string) $to, $reason, $version, AuditContext::fromRequest($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->volunteers->history(self::id($args, 'id')));
    }

    /**
     * @param array<string, string> $args
     */
    public function notes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->responder->success($request, $this->volunteers->notes(self::id($args, 'id'), AuditContext::fromRequest($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function addNote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = RequestInput::body($request, ['body']);
        $body = $input->string('body', 1, 5000);
        $input->validate();

        return $this->responder->success($request, $this->volunteers->addNote(self::principal($request), self::id($args, 'id'), $body, AuditContext::fromRequest($request)), 201);
    }

    /**
     * @param array{0: array<string, mixed>, 1: list<string>} $record
     */
    private function record(ServerRequestInterface $request, array $record, int $status = 200): ResponseInterface
    {
        return $this->responder->success($request, $record[0], $status, ['field_sets' => $record[1]]);
    }
}

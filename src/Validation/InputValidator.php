<?php

declare(strict_types=1);

namespace Ehf\Api\Validation;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;

/**
 * Validator layer foundation (API §2.11, §9): shapes, types and lengths, and rejection of
 * unknown fields. Validators never check permissions; field-level permission checks belong
 * to the authorisation policies (Phase 4).
 *
 * Endpoint validators (added from Phase 4 onwards) extend this class.
 */
abstract class InputValidator
{
    /**
     * Refuses any field the endpoint does not accept (`400 unknown_field`). This stops fields
     * such as `status` or `volunteer_id` being set through endpoints that do not accept them.
     *
     * @param array<array-key, mixed> $input
     * @param list<string>            $acceptedFields
     */
    final protected function rejectUnknownFields(array $input, array $acceptedFields): void
    {
        $details = [];
        foreach (array_keys($input) as $field) {
            if (!\in_array((string) $field, $acceptedFields, true)) {
                $details[] = new ErrorDetail((string) $field, 'unknown_field', 'This field is not accepted.');
            }
        }

        if ($details !== []) {
            throw new ApiException(ErrorCode::UnknownField, details: $details);
        }
    }

    /**
     * Throws `422 validation_failed` with every collected problem at once.
     *
     * @param list<ErrorDetail> $details
     */
    final protected function failIfAny(array $details): void
    {
        if ($details !== []) {
            throw new ApiException(ErrorCode::ValidationFailed, details: $details);
        }
    }
}

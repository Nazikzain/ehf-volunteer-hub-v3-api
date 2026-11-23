<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Support;

use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Validation\InputValidator;

/**
 * Exposes the protected InputValidator helpers to tests.
 */
final class ExampleValidator extends InputValidator
{
    /**
     * @param array<string, mixed> $input
     * @param list<string>         $accepted
     */
    public function check(array $input, array $accepted): void
    {
        $this->rejectUnknownFields($input, $accepted);
    }

    /**
     * @param list<ErrorDetail> $details
     */
    public function fail(array $details): void
    {
        $this->failIfAny($details);
    }
}

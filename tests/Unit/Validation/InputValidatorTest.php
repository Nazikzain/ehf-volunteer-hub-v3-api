<?php

declare(strict_types=1);

namespace Ehf\Api\Tests\Unit\Validation;

use Ehf\Api\Http\Error\ApiException;
use Ehf\Api\Http\Error\ErrorCode;
use Ehf\Api\Http\Error\ErrorDetail;
use Ehf\Api\Tests\Support\ExampleValidator;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    public function testAcceptedFieldsPass(): void
    {
        $this->validator()->check(['name' => 'x', 'note' => 'y'], ['name', 'note']);

        $this->addToAssertionCount(1);
    }

    public function testUnknownFieldsAreListedAndRefused(): void
    {
        try {
            $this->validator()->check(['name' => 'x', 'status' => 'active', 'volunteer_id' => 5], ['name']);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::UnknownField, $e->errorCode);
            self::assertSame(400, $e->httpStatus());
            self::assertSame(['status', 'volunteer_id'], array_map(static fn(ErrorDetail $d): string => $d->field, $e->details));
        }
    }

    public function testCollectedProblemsBecomeValidationFailed(): void
    {
        try {
            $this->validator()->fail([new ErrorDetail('name', 'required', 'Required.')]);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode);
            self::assertSame(422, $e->httpStatus());
        }
    }

    private function validator(): ExampleValidator
    {
        return new ExampleValidator();
    }
}

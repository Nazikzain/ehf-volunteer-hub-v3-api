<?php

declare(strict_types=1);

namespace Ehf\Api\Validation;

use Ehf\Api\Http\Error\ErrorDetail;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reading of a JSON body or query string with every problem collected, then reported at
 * once by validate() (`422 validation_failed`). Unknown fields are refused up front
 * (`400 unknown_field`). Call validate() before using any value.
 */
final class RequestInput extends InputValidator
{
    /** @var list<ErrorDetail> */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(private readonly array $data, private readonly bool $fromQuery) {}

    /**
     * @param list<string> $accepted
     */
    public static function body(ServerRequestInterface $request, array $accepted): self
    {
        $body = $request->getParsedBody();
        $data = \is_array($body) ? $body : [];
        $input = new self($data, false);
        $input->rejectUnknownFields($data, $accepted);

        return $input;
    }

    /**
     * @param list<string> $accepted
     */
    public static function query(ServerRequestInterface $request, array $accepted): self
    {
        $data = $request->getQueryParams();
        $input = new self($data, true);
        $input->rejectUnknownFields($data, $accepted);

        return $input;
    }

    /**
     * Fields present in the input, including those sent as null.
     *
     * @return list<string>
     */
    public function sentFields(): array
    {
        return array_map('strval', array_keys($this->data));
    }

    /**
     * The value as sent (null if absent); for validators that need the raw shape.
     */
    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->data) && $this->data[$field] !== null;
    }

    /**
     * Required text; '' if missing or invalid (an error is recorded).
     */
    public function string(string $field, int $min, int $max, bool $trim = true): string
    {
        return $this->optionalString($field, $min, $max, $trim, true) ?? '';
    }

    public function optionalString(string $field, int $min, int $max, bool $trim = true, bool $required = false): ?string
    {
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, 'required', 'This field is required.');
            }

            return null;
        }

        $value = $this->data[$field];
        if (!\is_string($value)) {
            $this->error($field, 'type', 'Must be text.');

            return null;
        }

        $value = $trim ? trim($value) : $value;
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min || $length > $max) {
            $this->error($field, 'length', \sprintf('Must be %d to %d characters.', $min, $max));

            return null;
        }

        return $value;
    }

    public function email(string $field): string
    {
        $value = $this->string($field, 3, 254);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->error($field, 'format', 'Must be a valid email address.');

            return '';
        }

        return mb_strtolower($value);
    }

    /**
     * Required integer ≥ $min; 0 if missing or invalid.
     */
    public function int(string $field, int $min = 1): int
    {
        return $this->optionalInt($field, $min, true) ?? 0;
    }

    public function optionalInt(string $field, int $min = 1, bool $required = false, ?int $max = null): ?int
    {
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, 'required', 'This field is required.');
            }

            return null;
        }

        $value = $this->data[$field];
        if ($this->fromQuery && \is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!\is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            $this->error($field, 'type', $max === null ? "Must be a whole number of at least {$min}." : "Must be a whole number from {$min} to {$max}.");

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function oneOf(string $field, array $allowed, bool $required = true): ?string
    {
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, 'required', 'This field is required.');
            }

            return null;
        }

        $value = $this->data[$field];
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            $this->error($field, 'not_allowed', 'Must be one of: ' . implode(', ', $allowed) . '.');

            return null;
        }

        return $value;
    }

    /**
     * Optional ISO 8601 timestamp, normalised to UTC.
     */
    public function optionalTimestamp(string $field): ?\DateTimeImmutable
    {
        $value = $this->optionalString($field, 10, 40);
        if ($value === null) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
        if ($time === false) {
            $time = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        }
        if ($time === false) {
            $this->error($field, 'format', 'Must be an ISO 8601 timestamp, for example 2026-12-01T09:00:00Z.');

            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    public function error(string $field, string $code, string $message): void
    {
        $this->errors[] = new ErrorDetail($field, $code, $message);
    }

    public function validate(): void
    {
        $this->failIfAny($this->errors);
    }
}

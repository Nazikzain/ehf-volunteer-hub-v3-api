<?php

declare(strict_types=1);

namespace Ehf\Api\Validation;

/**
 * Value rules for volunteer fields (Database V1.4 §5.3 column sizes). Which fields a caller may
 * send is decided separately (Authorisation\FieldPolicy). Optional fields may be cleared with
 * null; full_name may not.
 */
final class VolunteerInput
{
    /** field => [min, max] */
    private const array TEXT = [
        'full_name' => [1, 150],
        'preferred_name' => [1, 100],
        'skills' => [1, 2000],
        'languages' => [1, 2000],
        'interests' => [1, 2000],
        'address_line1' => [1, 150],
        'address_line2' => [1, 150],
        'town' => [1, 100],
        'nationality' => [1, 80],
        'emergency_contact_name' => [1, 150],
        'emergency_contact_relationship' => [1, 60],
    ];

    private const string PHONE = '/^\+?[0-9][0-9 ()\-]{4,38}[0-9]$/';

    private const string POSTCODE = '/^[A-Za-z0-9][A-Za-z0-9 ]{0,10}[A-Za-z0-9]$/';

    private function __construct() {}

    /**
     * @param list<string> $fields the volunteer fields that were sent
     *
     * @return array<string, mixed> field => normalised value (availability: ?array{type: string, days: list<int>})
     */
    public static function values(RequestInput $input, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $raw = $input->raw($field);
            if ($raw === null) {
                if ($field === 'full_name') {
                    $input->error($field, 'required', 'This field is required.');
                }
                $values[$field] = null;

                continue;
            }
            $values[$field] = match ($field) {
                'email' => self::email($input, $raw),
                'phone', 'emergency_contact_phone' => self::pattern($input, $field, $raw, self::PHONE, 'Must be a phone number (digits, spaces, brackets, hyphens, optional leading +).'),
                'postcode' => self::pattern($input, $field, $raw, self::POSTCODE, 'Must be a postcode (letters, digits and spaces, up to 12 characters).', true),
                'availability' => self::availability($input, $raw),
                default => $input->optionalString($field, ...self::TEXT[$field]),
            };
        }

        return $values;
    }

    private static function email(RequestInput $input, mixed $raw): ?string
    {
        if (!\is_string($raw) || mb_strlen(trim($raw)) > 254 || filter_var(trim($raw), FILTER_VALIDATE_EMAIL) === false) {
            $input->error('email', 'format', 'Must be a valid email address.');

            return null;
        }

        return mb_strtolower(trim($raw));
    }

    private static function pattern(RequestInput $input, string $field, mixed $raw, string $pattern, string $message, bool $upper = false): ?string
    {
        $value = \is_string($raw) ? trim($raw) : null;
        if ($value === null || preg_match($pattern, $value) !== 1) {
            $input->error($field, 'format', $message);

            return null;
        }

        return $upper ? strtoupper($value) : $value;
    }

    /**
     * @return array{type: string, days: list<int>}|null
     */
    private static function availability(RequestInput $input, mixed $raw): ?array
    {
        if (!\is_array($raw) || array_diff(array_keys($raw), ['type', 'days']) !== []) {
            $input->error('availability', 'type', 'Must be an object with "type" and "days".');

            return null;
        }
        $type = $raw['type'] ?? null;
        $days = $raw['days'] ?? [];
        if (!\in_array($type, ['specific_days', 'flexible'], true)) {
            $input->error('availability.type', 'not_allowed', 'Must be one of: specific_days, flexible.');

            return null;
        }
        if (!\is_array($days) || !array_is_list($days)) {
            $input->error('availability.days', 'type', 'Must be a list of weekdays (1 = Monday … 7 = Sunday).');

            return null;
        }
        $clean = [];
        foreach ($days as $day) {
            if (!\is_int($day) || $day < 1 || $day > 7) {
                $input->error('availability.days', 'type', 'Must be a list of weekdays (1 = Monday … 7 = Sunday).');

                return null;
            }
            $clean[$day] = $day;
        }
        ksort($clean);
        if ($type === 'specific_days' && $clean === []) {
            $input->error('availability.days', 'required', 'Choose at least one day.');

            return null;
        }
        if ($type === 'flexible' && $clean !== []) {
            $input->error('availability.days', 'not_allowed', 'Flexible availability has no days.');

            return null;
        }

        return ['type' => $type, 'days' => array_values($clean)];
    }
}

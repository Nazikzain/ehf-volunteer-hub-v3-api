<?php

declare(strict_types=1);

namespace Ehf\Api\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes secrets from log context and extra data before a record is written
 * (API §3 logging, §11 application logs, §15.2 secrets never logged).
 *
 * Any key whose name suggests a secret, token or credential is replaced, at any depth.
 */
final class RedactionProcessor implements ProcessorInterface
{
    public const string REDACTED = '[REDACTED]';

    private const string SENSITIVE_KEY = '/pass(word|wd|phrase)?|secret|token|authori[sz]ation|cookie|csrf|api[_-]?key|private[_-]?key|credential|dsn|session[_-]?id/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = \is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}

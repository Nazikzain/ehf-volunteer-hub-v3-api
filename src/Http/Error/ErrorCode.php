<?php

declare(strict_types=1);

namespace Ehf\Api\Http\Error;

/**
 * Stable error codes, exactly as listed in Backend API Architecture V1.3.1 §4.3.
 *
 * No code may be added here without an approved amendment to the API specification.
 * The OpenAPI contract enumerates the same values (checked by the contract tests).
 */
enum ErrorCode: string
{
    // 400
    case MalformedJson = 'malformed_json';
    case UnknownField = 'unknown_field';

    // 401
    case Unauthenticated = 'unauthenticated';
    case SessionExpired = 'session_expired';
    case InvalidCredentials = 'invalid_credentials';

    // 403
    case Forbidden = 'forbidden';
    case CsrfInvalid = 'csrf_invalid';
    case OriginNotAllowed = 'origin_not_allowed';
    case SelfDecisionForbidden = 'self_decision_forbidden';
    case SelfGrantForbidden = 'self_grant_forbidden';
    case OwnerAuthorityRequired = 'owner_authority_required';
    case FinalOwnerProtected = 'final_owner_protected';
    case PasswordConfirmationRequired = 'password_confirmation_required';
    case FieldNotPermitted = 'field_not_permitted';
    case FormatNotPermitted = 'format_not_permitted';
    case ReportNotPermitted = 'report_not_permitted';

    // 404
    case NotFound = 'not_found';

    // 409
    case InvalidTransition = 'invalid_transition';
    case StaleVersion = 'stale_version';
    case Duplicate = 'duplicate';
    case ActiveCertificateExists = 'active_certificate_exists';
    case NotEligible = 'not_eligible';
    case NotEligibleForProvisioning = 'not_eligible_for_provisioning';
    case AccountExists = 'account_exists';
    case PeriodCompleted = 'period_completed';
    case LockedByCertificate = 'locked_by_certificate';
    case TokenUsed = 'token_used';

    // 410
    case TokenExpired = 'token_expired';
    case ExportExpired = 'export_expired';

    // 413 / 415
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';

    // 422
    case ValidationFailed = 'validation_failed';
    case IdempotencyMismatch = 'idempotency_mismatch';
    case CombinationNotAllowed = 'combination_not_allowed';
    case UseOperationalException = 'use_operational_exception';
    case FieldNotEditable = 'field_not_editable';
    case TooManyFiles = 'too_many_files';
    case TooManyRows = 'too_many_rows';
    case PasswordPolicy = 'password_policy';

    // 429
    case RateLimited = 'rate_limited';

    // 500 / 503
    case InternalError = 'internal_error';
    case Maintenance = 'maintenance';

    public function httpStatus(): int
    {
        return match ($this) {
            self::MalformedJson, self::UnknownField => 400,
            self::Unauthenticated, self::SessionExpired, self::InvalidCredentials => 401,
            self::Forbidden, self::CsrfInvalid, self::OriginNotAllowed, self::SelfDecisionForbidden,
            self::SelfGrantForbidden, self::OwnerAuthorityRequired, self::FinalOwnerProtected,
            self::PasswordConfirmationRequired, self::FieldNotPermitted, self::FormatNotPermitted,
            self::ReportNotPermitted => 403,
            self::NotFound => 404,
            self::InvalidTransition, self::StaleVersion, self::Duplicate, self::ActiveCertificateExists,
            self::NotEligible, self::NotEligibleForProvisioning, self::AccountExists, self::PeriodCompleted,
            self::LockedByCertificate, self::TokenUsed => 409,
            self::TokenExpired, self::ExportExpired => 410,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed, self::IdempotencyMismatch, self::CombinationNotAllowed,
            self::UseOperationalException, self::FieldNotEditable, self::TooManyFiles, self::TooManyRows,
            self::PasswordPolicy => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::Maintenance => 503,
        };
    }

    /**
     * Plain-English message that is safe to show to any caller (API §9).
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::MalformedJson => 'The request body is not valid JSON.',
            self::UnknownField => 'The request contains fields that are not accepted.',
            self::Unauthenticated => 'You need to sign in.',
            self::SessionExpired => 'Your session has expired. Please sign in again.',
            self::InvalidCredentials => 'The email address or password is incorrect.',
            self::Forbidden => 'You do not have permission to do this.',
            self::CsrfInvalid => 'Your session token is invalid. Please refresh and try again.',
            self::OriginNotAllowed => 'Requests from this origin are not allowed.',
            self::SelfDecisionForbidden => 'You cannot make a decision on your own submission.',
            self::SelfGrantForbidden => 'You cannot change your own access.',
            self::OwnerAuthorityRequired => 'This action requires System Owner authority.',
            self::FinalOwnerProtected => 'This change would leave no System Owner.',
            self::PasswordConfirmationRequired => 'Please confirm your password to continue.',
            self::FieldNotPermitted => 'One or more fields are not available to you.',
            self::FormatNotPermitted => 'This export format is not available to you.',
            self::ReportNotPermitted => 'This report type is not available to you.',
            self::NotFound => 'The requested resource was not found.',
            self::InvalidTransition => 'This action is not possible in the record\'s current state.',
            self::StaleVersion => 'This record has changed. Please reload and try again.',
            self::Duplicate => 'A matching record already exists.',
            self::ActiveCertificateExists => 'An active certificate already exists for this period.',
            self::NotEligible => 'The eligibility requirements are not met.',
            self::NotEligibleForProvisioning => 'This record is not eligible for a portal account.',
            self::AccountExists => 'An account already exists.',
            self::PeriodCompleted => 'This period has been completed and cannot be changed.',
            self::LockedByCertificate => 'This record is covered by an issued certificate.',
            self::TokenUsed => 'This link has already been used.',
            self::TokenExpired => 'This link has expired.',
            self::ExportExpired => 'This export has expired.',
            self::PayloadTooLarge => 'The request is too large.',
            self::UnsupportedMediaType => 'The request content type is not supported.',
            self::ValidationFailed => 'Some fields need attention.',
            self::IdempotencyMismatch => 'This request key was already used with different content.',
            self::CombinationNotAllowed => 'This combination is not allowed.',
            self::UseOperationalException => 'Operational access for this account requires an operational exception.',
            self::FieldNotEditable => 'One or more fields cannot be edited.',
            self::TooManyFiles => 'Too many files.',
            self::TooManyRows => 'Too many rows.',
            self::PasswordPolicy => 'The password does not meet the password policy.',
            self::RateLimited => 'Too many requests. Please try again later.',
            self::InternalError => 'Something went wrong. Please try again later.',
            self::Maintenance => 'The service is temporarily unavailable for maintenance.',
        };
    }
}

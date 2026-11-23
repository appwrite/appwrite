<?php

namespace Appwrite\Distribution\Check;

enum Code: string
{
    case ConnectionMissing = 'connection_missing';
    case ConnectionUnauthorized = 'connection_unauthorized';
    case StoreAppMissing = 'store_app_missing';
    case StoreFirstUploadRequired = 'store_first_upload_required';
    case SigningMissing = 'signing_missing';
    case SigningExpired = 'signing_expired';
    case SigningIdentifierMismatch = 'signing_identifier_mismatch';
    case VersionNotIncreasing = 'version_not_increasing';
    case TargetSdkTooLow = 'target_sdk_too_low';
    case RunnerUnavailable = 'runner_unavailable';
    case DeploymentTargetTooLow = 'deployment_target_too_low';
    case ListingFieldMissing = 'listing_field_missing';
    case PrivacyLabelsMissing = 'privacy_labels_missing';
    case RestApiDisabled = 'rest_api_disabled';
    case StoreAgreementRequired = 'store_agreement_required';
    case ConnectionRoleInsufficient = 'connection_role_insufficient';
    case StoreReviewInProgress = 'store_review_in_progress';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $code): string => $code->value, self::cases());
    }
}

<?php

namespace Appwrite\Extend;

use Utopia\Config\Config;

class Exception extends \Exception
{
    /**
     * Error Codes
     *
     * Naming the error types based on the following convention
     * <ENTITY>_<ERROR_TYPE>
     *
     * Appwrite has the following entities:
     * - General
     * - Users
     * - Teams
     * - Memberships
     * - Avatars
     * - Storage
     * - Functions
     * - Deployments
     * - Executions
     * - Collections
     * - Documents
     * - Attributes
     * - Indexes
     * - Projects
     * - Webhooks
     * - Keys
     * - Platform
     * - Domain
     * - GraphQL
     * - Migrations
     */

    /** General */
    public const string GENERAL_UNKNOWN = 'general_unknown';
    public const string GENERAL_MOCK = 'general_mock';
    public const string GENERAL_ACCESS_FORBIDDEN = 'general_access_forbidden';
    public const string GENERAL_RESOURCE_BLOCKED = 'general_resource_blocked';
    public const string GENERAL_UNKNOWN_ORIGIN = 'general_unknown_origin';
    public const string GENERAL_API_DISABLED = 'general_api_disabled';
    public const string GENERAL_SERVICE_DISABLED = 'general_service_disabled';
    public const string GENERAL_UNAUTHORIZED_SCOPE = 'general_unauthorized_scope';
    public const string GENERAL_RATE_LIMIT_EXCEEDED = 'general_rate_limit_exceeded';
    public const string GENERAL_SMTP_DISABLED = 'general_smtp_disabled';
    public const string GENERAL_PHONE_DISABLED = 'general_phone_disabled';
    public const string GENERAL_ARGUMENT_INVALID = 'general_argument_invalid';
    public const string GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED = 'general_column_query_limit_exceeded';
    public const string GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED = 'general_attribute_query_limit_exceeded';
    public const string GENERAL_QUERY_INVALID = 'general_query_invalid';
    public const string GENERAL_ROUTE_NOT_FOUND = 'general_route_not_found';
    public const string GENERAL_CURSOR_NOT_FOUND = 'general_cursor_not_found';
    public const string GENERAL_SERVER_ERROR = 'general_server_error';
    public const string GENERAL_PROTOCOL_UNSUPPORTED = 'general_protocol_unsupported';
    public const string GENERAL_CODES_DISABLED = 'general_codes_disabled';
    public const string GENERAL_USAGE_DISABLED = 'general_usage_disabled';
    public const string GENERAL_NOT_IMPLEMENTED = 'general_not_implemented';
    public const string GENERAL_INVALID_EMAIL = 'general_invalid_email';
    public const string GENERAL_INVALID_PHONE = 'general_invalid_phone';
    public const string GENERAL_REGION_ACCESS_DENIED = 'general_region_access_denied';
    public const string GENERAL_BAD_REQUEST = 'general_bad_request';

    /** Users */
    public const string USER_COUNT_EXCEEDED = 'user_count_exceeded';
    public const string USER_CONSOLE_COUNT_EXCEEDED = 'user_console_count_exceeded';
    public const string USER_JWT_INVALID = 'user_jwt_invalid';
    public const string USER_ALREADY_EXISTS = 'user_already_exists';
    public const string USER_BLOCKED = 'user_blocked';
    public const string USER_INVALID_TOKEN = 'user_invalid_token';
    public const string USER_PASSWORD_RESET_REQUIRED = 'user_password_reset_required';
    public const string USER_EMAIL_NOT_WHITELISTED = 'user_email_not_whitelisted';
    public const string USER_IP_NOT_WHITELISTED = 'user_ip_not_whitelisted';
    public const string USER_INVALID_CODE = 'user_invalid_code';
    public const string USER_INVALID_CREDENTIALS = 'user_invalid_credentials';
    public const string USER_ANONYMOUS_CONSOLE_PROHIBITED = 'user_anonymous_console_prohibited';
    public const string USER_SESSION_ALREADY_EXISTS = 'user_session_already_exists';
    public const string USER_NOT_FOUND = 'user_not_found';
    public const string USER_PASSWORD_RECENTLY_USED = 'password_recently_used';
    public const string USER_PASSWORD_PERSONAL_DATA = 'password_personal_data';
    public const string USER_EMAIL_ALREADY_EXISTS = 'user_email_already_exists';
    public const string USER_PASSWORD_MISMATCH = 'user_password_mismatch';
    public const string USER_SESSION_NOT_FOUND = 'user_session_not_found';
    public const string USER_IDENTITY_NOT_FOUND = 'user_identity_not_found';
    public const string USER_UNAUTHORIZED = 'user_unauthorized';
    public const string USER_AUTH_METHOD_UNSUPPORTED = 'user_auth_method_unsupported';
    public const string USER_PHONE_ALREADY_EXISTS = 'user_phone_already_exists';
    public const string USER_PHONE_NOT_FOUND = 'user_phone_not_found';
    public const string USER_PHONE_NOT_VERIFIED = 'user_phone_not_verified';
    public const string USER_EMAIL_NOT_FOUND = 'user_email_not_found';
    public const string USER_EMAIL_NOT_VERIFIED = 'user_email_not_verified';
    public const string USER_MISSING_ID = 'user_missing_id';
    public const string USER_MORE_FACTORS_REQUIRED = 'user_more_factors_required';
    public const string USER_INVALID_CHALLENGE = 'user_invalid_challenge';
    public const string USER_AUTHENTICATOR_NOT_FOUND = 'user_authenticator_not_found';
    public const string USER_AUTHENTICATOR_ALREADY_VERIFIED = 'user_authenticator_already_verified';
    public const string USER_RECOVERY_CODES_ALREADY_EXISTS = 'user_recovery_codes_already_exists';
    public const string USER_RECOVERY_CODES_NOT_FOUND = 'user_recovery_codes_not_found';
    public const string USER_CHALLENGE_REQUIRED = 'user_challenge_required';
    public const string USER_OAUTH2_BAD_REQUEST = 'user_oauth2_bad_request';
    public const string USER_OAUTH2_UNAUTHORIZED = 'user_oauth2_unauthorized';
    public const string USER_OAUTH2_PROVIDER_ERROR = 'user_oauth2_provider_error';
    public const string USER_EMAIL_ALREADY_VERIFIED = 'user_email_already_verified';
    public const string USER_PHONE_ALREADY_VERIFIED = 'user_phone_already_verified';
    public const string USER_DELETION_PROHIBITED = 'user_deletion_prohibited';
    public const string USER_TARGET_NOT_FOUND = 'user_target_not_found';
    public const string USER_TARGET_ALREADY_EXISTS = 'user_target_already_exists';
    public const string USER_API_KEY_AND_SESSION_SET = 'user_key_and_session_set';

    public const string API_KEY_EXPIRED = 'api_key_expired';

    /** Teams */
    public const string TEAM_NOT_FOUND = 'team_not_found';
    public const string TEAM_INVITE_NOT_FOUND = 'team_invite_not_found';
    public const string TEAM_INVALID_SECRET = 'team_invalid_secret';
    public const string TEAM_MEMBERSHIP_MISMATCH = 'team_membership_mismatch';
    public const string TEAM_INVITE_MISMATCH = 'team_invite_mismatch';
    public const string TEAM_ALREADY_EXISTS = 'team_already_exists';

    /** Console */
    public const string RESOURCE_ALREADY_EXISTS = 'resource_already_exists';

    /** Membership */
    public const string MEMBERSHIP_NOT_FOUND = 'membership_not_found';
    public const string MEMBERSHIP_ALREADY_CONFIRMED = 'membership_already_confirmed';
    public const string MEMBERSHIP_DELETION_PROHIBITED = 'membership_deletion_prohibited';
    public const string MEMBERSHIP_DOWNGRADE_PROHIBITED = 'membership_downgrade_prohibited';

    /** Avatars */
    public const string AVATAR_SET_NOT_FOUND = 'avatar_set_not_found';
    public const string AVATAR_NOT_FOUND = 'avatar_not_found';
    public const string AVATAR_IMAGE_NOT_FOUND = 'avatar_image_not_found';
    public const string AVATAR_REMOTE_URL_FAILED = 'avatar_remote_url_failed';
    public const string AVATAR_ICON_NOT_FOUND = 'avatar_icon_not_found';
    public const string AVATAR_SVG_SANITIZATION_FAILED = 'avatar_svg_sanitization_failed';

    /** Storage */
    public const string STORAGE_FILE_ALREADY_EXISTS = 'storage_file_already_exists';
    public const string STORAGE_FILE_NOT_FOUND = 'storage_file_not_found';
    public const string STORAGE_DEVICE_NOT_FOUND = 'storage_device_not_found';
    public const string STORAGE_FILE_EMPTY = 'storage_file_empty';
    public const string STORAGE_FILE_TYPE_UNSUPPORTED = 'storage_file_type_unsupported';
    public const string STORAGE_INVALID_FILE_SIZE = 'storage_invalid_file_size';
    public const string STORAGE_INVALID_FILE = 'storage_invalid_file';
    public const string STORAGE_BUCKET_ALREADY_EXISTS = 'storage_bucket_already_exists';
    public const string STORAGE_BUCKET_NOT_FOUND = 'storage_bucket_not_found';
    public const string STORAGE_INVALID_CONTENT_RANGE = 'storage_invalid_content_range';
    public const string STORAGE_INVALID_RANGE = 'storage_invalid_range';
    public const string STORAGE_INVALID_APPWRITE_ID = 'storage_invalid_appwrite_id';
    public const string STORAGE_FILE_NOT_PUBLIC = 'storage_file_not_public';
    public const string STORAGE_BUCKET_TRANSFORMATIONS_DISABLED = 'storage_bucket_transformations_disabled';

    /** VCS */
    public const string INSTALLATION_NOT_FOUND = 'installation_not_found';
    public const string PROVIDER_REPOSITORY_NOT_FOUND = 'provider_repository_not_found';
    public const string REPOSITORY_NOT_FOUND = 'repository_not_found';
    public const string PROVIDER_CONTRIBUTION_CONFLICT = 'provider_contribution_conflict';
    public const string GENERAL_PROVIDER_FAILURE = 'general_provider_failure';

    /** Sites */
    public const string SITE_NOT_FOUND = 'site_not_found';
    public const string SITE_TEMPLATE_NOT_FOUND = 'site_template_not_found';

    /** Functions */
    public const string FUNCTION_NOT_FOUND = 'function_not_found';
    public const string FUNCTION_ALREADY_EXISTS = 'function_already_exists';
    public const string FUNCTION_RUNTIME_UNSUPPORTED = 'function_runtime_unsupported';
    public const string FUNCTION_ENTRYPOINT_MISSING = 'function_entrypoint_missing';
    public const string FUNCTION_SYNCHRONOUS_TIMEOUT = 'function_synchronous_timeout';
    public const string FUNCTION_TEMPLATE_NOT_FOUND = 'function_template_not_found';
    public const string FUNCTION_RUNTIME_NOT_DETECTED = 'function_runtime_not_detected';
    public const string FUNCTION_EXECUTE_PERMISSION_MISSING = 'function_execute_permission_missing';

    /** Deployments */
    public const string DEPLOYMENT_NOT_FOUND = 'deployment_not_found';

    /** Builds */
    public const string BUILD_NOT_FOUND = 'build_not_found';
    public const string BUILD_NOT_READY = 'build_not_ready';
    public const string BUILD_IN_PROGRESS = 'build_in_progress';
    public const string BUILD_ALREADY_COMPLETED = 'build_already_completed';
    public const string BUILD_CANCELED = 'build_canceled';
    public const string BUILD_FAILED = 'build_failed';

    /** Execution */
    public const string EXECUTION_NOT_FOUND = 'execution_not_found';
    public const string EXECUTION_IN_PROGRESS = 'execution_in_progress';

    /** Log */
    public const string LOG_NOT_FOUND = 'log_not_found';

    /** Databases */
    public const string DATABASE_NOT_FOUND = 'database_not_found';
    public const string DATABASE_ALREADY_EXISTS = 'database_already_exists';
    public const string DATABASE_TIMEOUT = 'database_timeout';
    public const string DATABASE_QUERY_ORDER_NULL = 'database_query_order_null';

    /** Collections */
    public const string COLLECTION_NOT_FOUND = 'collection_not_found';
    public const string COLLECTION_ALREADY_EXISTS = 'collection_already_exists';
    public const string COLLECTION_LIMIT_EXCEEDED = 'collection_limit_exceeded';

    /** Tables */
    public const string TABLE_NOT_FOUND = 'table_not_found';
    public const string TABLE_ALREADY_EXISTS = 'table_already_exists';
    public const string TABLE_LIMIT_EXCEEDED = 'table_limit_exceeded';

    /** Documents */
    public const string DOCUMENT_NOT_FOUND = 'document_not_found';
    public const string DOCUMENT_INVALID_STRUCTURE = 'document_invalid_structure';
    public const string DOCUMENT_MISSING_DATA = 'document_missing_data';
    public const string DOCUMENT_MISSING_PAYLOAD = 'document_missing_payload';
    public const string DOCUMENT_ALREADY_EXISTS = 'document_already_exists';
    public const string DOCUMENT_UPDATE_CONFLICT = 'document_update_conflict';
    public const string DOCUMENT_DELETE_RESTRICTED = 'document_delete_restricted';

    /** Rows */
    public const string ROW_NOT_FOUND = 'row_not_found';
    public const string ROW_INVALID_STRUCTURE = 'row_invalid_structure';
    public const string ROW_MISSING_DATA = 'row_missing_data';
    public const string ROW_MISSING_PAYLOAD = 'row_missing_payload';
    public const string ROW_ALREADY_EXISTS = 'row_already_exists';
    public const string ROW_UPDATE_CONFLICT = 'row_update_conflict';
    public const string ROW_DELETE_RESTRICTED = 'row_delete_restricted';

    /** Attributes */
    public const string ATTRIBUTE_NOT_FOUND = 'attribute_not_found';
    public const string ATTRIBUTE_UNKNOWN = 'attribute_unknown';
    public const string ATTRIBUTE_NOT_AVAILABLE = 'attribute_not_available';
    public const string ATTRIBUTE_FORMAT_UNSUPPORTED = 'attribute_format_unsupported';
    public const string ATTRIBUTE_DEFAULT_UNSUPPORTED = 'attribute_default_unsupported';
    public const string ATTRIBUTE_ALREADY_EXISTS = 'attribute_already_exists';
    public const string ATTRIBUTE_LIMIT_EXCEEDED = 'attribute_limit_exceeded';
    public const string ATTRIBUTE_VALUE_INVALID = 'attribute_value_invalid';
    public const string ATTRIBUTE_TYPE_INVALID = 'attribute_type_invalid';
    public const string ATTRIBUTE_INVALID_RESIZE = 'attribute_invalid_resize';

    public const ATTRIBUTE_TYPE_NOT_SUPPORTED              = 'ATTRIBUTE_TYPE_NOT_SUPPORTED';

    /** Columns */
    public const string COLUMN_NOT_FOUND = 'column_not_found';
    public const string COLUMN_UNKNOWN = 'column_unknown';
    public const string COLUMN_NOT_AVAILABLE = 'column_not_available';
    public const string COLUMN_FORMAT_UNSUPPORTED = 'column_format_unsupported';
    public const string COLUMN_DEFAULT_UNSUPPORTED = 'column_default_unsupported';
    public const string COLUMN_ALREADY_EXISTS = 'column_already_exists';
    public const string COLUMN_LIMIT_EXCEEDED = 'column_limit_exceeded';
    public const string COLUMN_VALUE_INVALID = 'column_value_invalid';
    public const string COLUMN_TYPE_INVALID = 'column_type_invalid';
    public const string COLUMN_INVALID_RESIZE = 'column_invalid_resize';

    public const COLUMN_TYPE_NOT_SUPPORTED              = 'COLUMN_TYPE_NOT_SUPPORTED';

    /** Relationship */
    public const string RELATIONSHIP_VALUE_INVALID = 'relationship_value_invalid';

    /** Indexes */
    public const string INDEX_NOT_FOUND = 'index_not_found';
    public const string INDEX_LIMIT_EXCEEDED = 'index_limit_exceeded';
    public const string INDEX_ALREADY_EXISTS = 'index_already_exists';
    public const string INDEX_INVALID = 'index_invalid';
    public const string INDEX_DEPENDENCY = 'index_dependency';

    /** Column Indexes */
    public const string COLUMN_INDEX_NOT_FOUND = 'column_index_not_found';
    public const string COLUMN_INDEX_LIMIT_EXCEEDED = 'column_index_limit_exceeded';
    public const string COLUMN_INDEX_ALREADY_EXISTS = 'column_index_already_exists';
    public const string COLUMN_INDEX_INVALID = 'column_index_invalid';
    public const string COLUMN_INDEX_DEPENDENCY = 'column_index_dependency';

    /** Transactions */
    public const string TRANSACTION_NOT_FOUND = 'transaction_not_found';
    public const string TRANSACTION_ALREADY_EXISTS = 'transaction_already_exists';
    public const string TRANSACTION_INVALID = 'transaction_invalid';
    public const string TRANSACTION_FAILED = 'transaction_failed';
    public const string TRANSACTION_EXPIRED = 'transaction_expired';
    public const string TRANSACTION_CONFLICT = 'transaction_conflict';
    public const string TRANSACTION_LIMIT_EXCEEDED = 'transaction_limit_exceeded';
    public const string TRANSACTION_NOT_READY = 'transaction_not_ready';


    /** Projects */
    public const string PROJECT_NOT_FOUND = 'project_not_found';
    public const string PROJECT_PROVIDER_DISABLED = 'project_provider_disabled';
    public const string PROJECT_PROVIDER_UNSUPPORTED = 'project_provider_unsupported';
    public const string PROJECT_ALREADY_EXISTS = 'project_already_exists';
    public const string PROJECT_INVALID_SUCCESS_URL = 'project_invalid_success_url';
    public const string PROJECT_INVALID_FAILURE_URL = 'project_invalid_failure_url';
    public const string PROJECT_RESERVED_PROJECT = 'project_reserved_project';
    public const string PROJECT_KEY_EXPIRED = 'project_key_expired';

    public const string PROJECT_SMTP_CONFIG_INVALID = 'project_smtp_config_invalid';

    public const string PROJECT_TEMPLATE_DEFAULT_DELETION = 'project_template_default_deletion';

    public const string PROJECT_REGION_UNSUPPORTED = 'project_region_unsupported';

    /** Webhooks */
    public const string WEBHOOK_NOT_FOUND = 'webhook_not_found';

    /** Router */
    public const string ROUTER_HOST_NOT_FOUND = 'router_host_not_found';
    public const string ROUTER_DOMAIN_NOT_CONFIGURED = 'router_domain_not_configured';

    /** Proxy */
    public const string RULE_RESOURCE_NOT_FOUND = 'rule_resource_not_found';
    public const string RULE_NOT_FOUND = 'rule_not_found';
    public const string RULE_ALREADY_EXISTS = 'rule_already_exists';
    public const string RULE_VERIFICATION_FAILED = 'rule_verification_failed';

    /** Keys */
    public const string KEY_NOT_FOUND = 'key_not_found';

    /** Variables */
    public const string VARIABLE_NOT_FOUND = 'variable_not_found';
    public const string VARIABLE_ALREADY_EXISTS = 'variable_already_exists';
    public const string VARIABLE_CANNOT_UNSET_SECRET = 'variable_cannot_unset_secret';

    /** Platform */
    public const string PLATFORM_NOT_FOUND = 'platform_not_found';

    /** GraphqQL */
    public const string GRAPHQL_NO_QUERY = 'graphql_no_query';
    public const string GRAPHQL_TOO_MANY_QUERIES = 'graphql_too_many_queries';

    /** Migrations */
    public const string MIGRATION_NOT_FOUND = 'migration_not_found';
    public const string MIGRATION_ALREADY_EXISTS = 'migration_already_exists';
    public const string MIGRATION_IN_PROGRESS = 'migration_in_progress';
    public const string MIGRATION_PROVIDER_ERROR = 'migration_provider_error';

    /** Realtime */
    public const string REALTIME_MESSAGE_FORMAT_INVALID = 'realtime_message_format_invalid';
    public const string REALTIME_TOO_MANY_MESSAGES = 'realtime_too_many_messages';
    public const string REALTIME_POLICY_VIOLATION = 'realtime_policy_violation';

    /** Health */
    public const string HEALTH_QUEUE_SIZE_EXCEEDED = 'health_queue_size_exceeded';
    public const string HEALTH_CERTIFICATE_EXPIRED = 'health_certificate_expired';
    public const string HEALTH_INVALID_HOST = 'health_invalid_host';

    /** Provider */
    public const string PROVIDER_NOT_FOUND = 'provider_not_found';
    public const string PROVIDER_ALREADY_EXISTS = 'provider_already_exists';
    public const string PROVIDER_INCORRECT_TYPE = 'provider_incorrect_type';
    public const string PROVIDER_MISSING_CREDENTIALS = 'provider_missing_credentials';

    /** Topic */
    public const string TOPIC_NOT_FOUND = 'topic_not_found';
    public const string TOPIC_ALREADY_EXISTS = 'topic_already_exists';

    /** Subscriber */
    public const string SUBSCRIBER_NOT_FOUND = 'subscriber_not_found';
    public const string SUBSCRIBER_ALREADY_EXISTS = 'subscriber_already_exists';

    /** Message */
    public const string MESSAGE_NOT_FOUND = 'message_not_found';
    public const string MESSAGE_MISSING_TARGET = 'message_missing_target';
    public const string MESSAGE_ALREADY_SENT = 'message_already_sent';
    public const string MESSAGE_ALREADY_PROCESSING = 'message_already_processing';
    public const string MESSAGE_ALREADY_FAILED = 'message_already_failed';
    public const string MESSAGE_ALREADY_SCHEDULED = 'message_already_scheduled';
    public const string MESSAGE_TARGET_NOT_EMAIL = 'message_target_not_email';
    public const string MESSAGE_TARGET_NOT_SMS = 'message_target_not_sms';
    public const string MESSAGE_TARGET_NOT_PUSH = 'message_target_not_push';
    public const string MESSAGE_MISSING_SCHEDULE = 'message_missing_schedule';

    /** Targets */
    public const string TARGET_PROVIDER_INVALID_TYPE = 'target_provider_invalid_type';

    /** Schedules */
    public const string SCHEDULE_NOT_FOUND = 'schedule_not_found';

    /** Tokens */
    public const string TOKEN_NOT_FOUND = 'token_not_found';
    public const string TOKEN_EXPIRED = 'token_expired';
    public const string TOKEN_RESOURCE_TYPE_INVALID = 'token_resource_type_invalid';

    protected string $type = '';
    protected array $errors = [];
    protected bool $publish;
    private array $ctas = [];
    private ?string $view = null;

    public function __construct(
        string $type = Exception::GENERAL_UNKNOWN,
        string $message = null,
        int|string $code = null,
        \Throwable $previous = null,
        ?string $view = null,
        array $params = []
    ) {
        $this->errors = Config::getParam('errors');
        $this->type = $type;
        $this->view = $view;
        $this->code = $code ?? $this->errors[$type]['code'];

        // Mark string errors like HY001 from PDO as 500 errors
        if (\is_string($this->code)) {
            if (\is_numeric($this->code)) {
                $this->code = (int)$this->code;
            } else {
                $this->code = 500;
            }
        }

        // Format message with params if provided
        if (!empty($params) && $message === null) {
            $description = $this->errors[$type]['description'] ?? '';
            $this->message = !empty($description) ? sprintf($description, ...$params) : '';
        } else {
            $this->message = $message ?? $this->errors[$type]['description'];
        }

        $this->publish = $this->errors[$type]['publish'] ?? ($this->code >= 500);

        parent::__construct($this->message, $this->code, $previous);
    }

    /**
     * Get the type of the exception.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the type of the exception.
     *
     * @param string $type
     *
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * Check whether the log is publishable for the exception.
     *
     * @return bool
     */
    public function isPublishable(): bool
    {
        return $this->publish;
    }

    public function addCTA(string $label, ?string $url = null): self
    {
        $this->ctas[] = [
            'label' => $label,
            'url' => $url
        ];
        return $this;
    }

    public function getCTAs(): array
    {
        return $this->ctas;
    }

    public function getView(): ?string
    {
        return $this->view;
    }
}

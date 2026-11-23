<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Distribution\Check\Code;
use Appwrite\Distribution\Check\Severity;
use Appwrite\Distribution\Platform;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DistributionCheckIssue extends Model
{
    public function __construct()
    {
        $this
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Machine-readable issue code, one of `' . \implode('`, `', Code::values()) . '`. New codes may be added.',
                'default' => '',
                'example' => Code::SigningExpired->value,
            ])
            ->addRule('severity', [
                'type' => self::TYPE_ENUM,
                'description' => 'Whether the issue blocks (`error`) or only warns (`warning`).',
                'default' => Severity::Error->value,
                'example' => Severity::Error->value,
                'enum' => Severity::values(),
                'enumSDKName' => 'DistributionCheckSeverity',
            ])
            ->addRule('platform', [
                'type' => self::TYPE_STRING,
                'description' => 'Platform the issue applies to. Empty for an app-wide issue, otherwise one of `' . \implode('`, `', Platform::values()) . '`.',
                'default' => '',
                'example' => Platform::Ios->value,
            ])
            ->addRule('field', [
                'type' => self::TYPE_STRING,
                'description' => 'Field the issue points at. Empty when it points at none.',
                'default' => '',
                'example' => 'signingIdentityId',
            ])
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Human-readable description of the issue.',
                'default' => '',
                'example' => 'The signing certificate expired.',
            ])
            ->addRule('remediation', [
                'type' => self::TYPE_STRING,
                'description' => 'How to resolve the issue.',
                'default' => '',
                'example' => 'Import a new certificate or generate a managed identity.',
            ])
            ->addRule('manualActionRequired', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the issue can only be resolved outside Appwrite, such as in the store console.',
                'default' => false,
                'example' => false,
            ]);
    }

    public function getName(): string
    {
        return 'DistributionCheckIssue';
    }

    public function getType(): string
    {
        return Response::MODEL_DISTRIBUTION_CHECK_ISSUE;
    }
}

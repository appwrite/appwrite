<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\SMTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;
    use \Appwrite\Platform\Modules\Project\Http\Project\UpdatesProject;

    public static function getName()
    {
        return 'updateProjectSMTP';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/smtp')
            ->httpAlias('/v1/projects/:projectId/smtp')
            ->desc('Update project SMTP configuration')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            // ->label('event', 'project.smtp.update')
            ->label('audits.event', 'project.smtp.update')
            ->label('audits.resource', 'project.smtp/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'smtp',
                name: 'updateSMTP',
                description: <<<EOT
                Update the SMTP configuration for your project. Use this endpoint to configure your project's SMTP provider with your custom settings for sending transactional emails.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('host', null, new Nullable(new Hostname()), 'SMTP server hostname (domain)', optional: true)
            ->param('port', null, new Nullable(new Integer()), 'SMTP server port', optional: true)
            ->param('username', null, new Nullable(new Text(256, 0)), 'SMTP server username. Pass an empty string to clear a previously set value.', optional: true)
            ->param('password', null, new Nullable(new PasswordFormat(new Text(256, 0))), 'SMTP server password. Pass an empty string to clear a previously set value. This property is stored securely and cannot be read in future (write-only).', optional: true)
            ->param('senderEmail', null, new Nullable(new Email(allowEmpty: true)), 'Email address shown in inbox as the sender of the email. Pass an empty string to clear a previously set value.', optional: true)
            ->param('senderName', null, new Nullable(new Text(256, 0)), 'Name shown in inbox as the sender of the email. Pass an empty string to clear a previously set value.', optional: true)
            ->param('replyToEmail', null, new Nullable(new Email(allowEmpty: true)), 'Email used when user replies to the email. Pass an empty string to clear a previously set value.', optional: true)
            ->param('replyToName', null, new Nullable(new Text(256, 0)), 'Name used when user replies to the email. Pass an empty string to clear a previously set value.', optional: true)
            ->param('secure', null, new Nullable(new WhiteList(['tls', 'ssl'], true)), 'Configures if communication with SMTP server is encrypted. Allowed values are: tls, ssl. Leave empty for no encryption.', optional: true, enum: new Enum(name: 'ProjectSMTPSecure'))
            ->param('enabled', null, new Nullable(new Boolean()), 'Enable or disable custom SMTP. Custom SMTP is useful for branding purposes, but also allows use of custom email templates.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }


    public function action(
        ?string $host,
        ?int $port,
        ?string $username,
        ?string $password,
        ?string $senderEmail,
        ?string $senderName,
        ?string $replyToEmail,
        ?string $replyToName,
        ?string $secure,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        // Fetch current configuration. This read is only used to validate the
        // effective config (incl. the SMTP connection test below); the value
        // actually persisted is re-read fresh under a row lock at write time so
        // a concurrent update can't be clobbered.
        $smtp = $project->getAttribute('smtp', []);

        // Collect only the fields the caller provided. null means "not provided,
        // keep existing"; empty string explicitly clears a previously-set value.
        $keys = ['host', 'port', 'username', 'password', 'senderEmail', 'senderName', 'replyToEmail', 'replyToName', 'secure', 'enabled'];
        $changes = [];
        foreach ($keys as $key) {
            if (!\is_null(${$key})) {
                $changes[$key] = ${$key};
            }
        }

        // Effective config = stored values overlaid with this request's changes.
        $smtp = \array_merge($smtp, $changes);

        // Backwards compatibility
        $smtp['replyToEmail'] = $changes['replyToEmail'] = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';

        if (($smtp['enabled'] ?? false) === true) {
            // Ensure required fields are set
            $requiredKeys = ['host', 'port', 'senderEmail'];
            foreach ($requiredKeys as $key) {
                if (empty($smtp[$key])) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "' . $key . '" is not optional.');
                }
            }
        }

        // Validate SMTP credentials
        // Validate when the caller is explicitly enabling or hasn't expressed a preference
        // (so a credentials-only PATCH can auto-enable). Skip only when the caller is
        // explicitly keeping/turning SMTP off.
        if ((\is_null($enabled) || $enabled === true) && !empty($smtp['senderEmail'] ?? '')) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();

            $mail->Host = $smtp['host'] ?? '';
            $mail->Port = $smtp['port'] ?? '';
            $mail->SMTPSecure = $smtp['secure'] ?? '';
            $mail->setFrom($smtp['senderEmail'], $smtp['senderName'] ?? '');

            if (!empty($smtp['username'] ?? '')) {
                $mail->SMTPAuth = true;
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'] ?? '';
            }

            if (!empty($smtp['replyToEmail'] ?? '')) {
                $mail->addReplyTo($smtp['replyToEmail'], $smtp['replyToName'] ?? '');
            }

            $mail->SMTPAutoTLS = false;
            $mail->Timeout = 5;

            try {
                $valid = $mail->SmtpConnect();

                if (!$valid) {
                    throw new \Exception('Connection is not valid.');
                }

                // Auto-enable if configuration is valid
                // Dont do this if specifically request to mark disabled
                if (\is_null($enabled)) {
                    $smtp['enabled'] = $changes['enabled'] = true;
                }
            } catch (Throwable $error) {
                if (($smtp['enabled'] ?? null) === true) {
                    throw new Exception(Exception::PROJECT_SMTP_CONFIG_INVALID, $error->getMessage());
                }
            }
        }

        // Save configuration. Re-read fresh under a row lock and apply only the
        // changed keys so a concurrent SMTP update can't be clobbered. The
        // expensive connection test above intentionally stays outside the lock.
        $project = $this->updateProjectDocument($dbForPlatform, $authorization, $project, function (Document $current) use ($dbForPlatform, $changes) {
            $smtp = \array_merge($current->getAttribute('smtp', []), $changes);

            return $dbForPlatform->updateDocument('projects', $current->getId(), new Document([
                'smtp' => $smtp,
            ]));
        });

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

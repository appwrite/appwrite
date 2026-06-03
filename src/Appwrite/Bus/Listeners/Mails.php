<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Auth\MFA\Type;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Template\Template;
use Utopia\Bus\Listener;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Locale\Locale;
use Utopia\Storage\Validator\FileName;
use Utopia\System\System;

class Mails extends Listener
{
    public static function getName(): string
    {
        return 'mails';
    }

    public static function getEvents(): array
    {
        return [SessionCreated::class];
    }

    public function __construct()
    {
        $this
            ->desc('Sends session alert emails')
            ->inject('publisherForMails')
            ->inject('locale')
            ->inject('platform')
            ->inject('dbForProject')
            ->callback($this->handle(...));
    }

    public function handle(SessionCreated $event, MailPublisher $publisherForMails, Locale $locale, array $platform, Database $dbForProject): void
    {
        $project = new Document($event->project);

        if (!($project->getAttribute('auths', [])['sessionAlerts'] ?? false)) {
            return;
        }

        if (empty($event->user['email'])) {
            return;
        }

        $provider = $event->session['provider'] ?? '';
        $factors = $event->session['factors'] ?? [];

        if (\in_array($provider, [SESSION_PROVIDER_MAGIC_URL, SESSION_PROVIDER_TOKEN]) && \in_array(Type::EMAIL, $factors)) {
            return;
        }

        if ($dbForProject->count('sessions', [Query::equal('userId', [$event->user['$id']])]) === 1) {
            return;
        }

        $locale->setDefault($event->locale);

        $session = new Document($event->session);
        $smtp = $project->getAttribute('smtp', []);
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        if (!(new FileName())->isValid($smtpBaseTemplate)) {
            throw new \Exception('Invalid template path');
        }

        $customTemplate =
            $project->getAttribute('templates', [])["email.sessionAlert-" . $locale->default] ??
            $project->getAttribute('templates', [])['email.sessionAlert-' . $locale->fallback] ?? [];
        $isBranded = $smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE;

        $subject = $customTemplate['subject'] ?? $locale->getText('emails.sessionAlert.subject');
        $preview = $locale->getText('emails.sessionAlert.preview');

        $body = empty($customTemplate['message'])
            ? Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-session-alert.tpl')
                ->setParam('{{hello}}', $locale->getText('emails.sessionAlert.hello'))
                ->setParam('{{body}}', $locale->getText('emails.sessionAlert.body'))
                ->setParam('{{listDevice}}', $locale->getText('emails.sessionAlert.listDevice'))
                ->setParam('{{listIpAddress}}', $locale->getText('emails.sessionAlert.listIpAddress'))
                ->setParam('{{listCountry}}', $locale->getText('emails.sessionAlert.listCountry'))
                ->setParam('{{footer}}', $locale->getText('emails.sessionAlert.footer'))
                ->setParam('{{thanks}}', $locale->getText('emails.sessionAlert.thanks'))
                ->setParam('{{signature}}', $locale->getText('emails.sessionAlert.signature'))
                ->render()
            : $customTemplate['message'];

        $clientName = $session->getAttribute('clientName')
            ?: ($session->getAttribute('userAgent') ?: 'UNKNOWN');

        $projectName = $project->getId() === 'console'
            ? $platform['platformName']
            : $project->getAttribute('name');

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'date'      => (new \DateTime())->format('F j'),
            'year'      => (new \DateTime())->format('YYYY'),
            'time'      => (new \DateTime())->format('H:i:s'),
            'user'      => $event->user['name'] ?? '',
            'project'   => $projectName,
            'device'    => $clientName,
            'ipAddress' => $session->getAttribute('ip'),
            'country'   => $locale->getText('countries.' . $session->getAttribute('countryCode'), $locale->getText('locale.country.unknown')),
        ];

        if ($isBranded) {
            $emailVariables += [
                'accentColor' => $platform['accentColor'],
                'logoUrl'     => $platform['logoUrl'],
                'twitter'     => $platform['twitterUrl'],
                'discord'     => $platform['discordUrl'],
                'github'      => $platform['githubUrl'],
                'terms'       => $platform['termsUrl'],
                'privacy'     => $platform['privacyUrl'],
                'platform'    => $platform['platformName'],
            ];
        }

        $smtpConfig = [];
        if ($smtp['enabled'] ?? false) {
            $smtpConfig = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? '',
                'secure' => $smtp['secure'] ?? '',
                'replyToEmail' => $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '', // Includes backwards compatibility
                'replyToName' => $customTemplate['replyToName'] ?? $smtp['replyToName'] ?? '',
                'senderEmail' => $customTemplate['senderEmail'] ?? $smtp['senderEmail'] ?? System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
                'senderName' => $customTemplate['senderName'] ?? $smtp['senderName'] ?? System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'),
            ];
        }

        $publisherForMails->enqueue(new MailMessage(
            project: $project,
            recipient: $event->user['email'],
            subject: $subject,
            bodyTemplate: __DIR__ . '/../../../../app/config/locale/templates/' . $smtpBaseTemplate . '.tpl',
            body: $body,
            preview: $preview,
            smtp: $smtpConfig,
            variables: $emailVariables,
            customMailOptions: $isBranded ? ['senderName' => $platform['emailSenderName']] : [],
            platform: $platform,
        ));
    }
}

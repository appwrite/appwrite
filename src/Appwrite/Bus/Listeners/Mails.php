<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Auth\MFA\Type;
use Appwrite\Bus\Events\SessionCreated;
use Appwrite\Event\Mail;
use Appwrite\Template\Template;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Queue\Publisher;
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
            ->inject('publisher')
            ->inject('locale')
            ->inject('platform')
            ->callback($this->handle(...));
    }

    public function handle(SessionCreated $event, Publisher $publisher, Locale $locale, array $platform): void
    {
        $provider = $event->session['provider'] ?? '';
        $factors = $event->session['factors'] ?? [];
        $isEmailLinkSession = in_array($provider, [SESSION_PROVIDER_MAGIC_URL, SESSION_PROVIDER_TOKEN])
            && in_array(Type::EMAIL, $factors);

        $hasUserEmail = !empty($event->user['email']);
        $isSessionAlertsEnabled = $event->project['auths']['sessionAlerts'] ?? false;

        if ($isEmailLinkSession || !$hasUserEmail || !$isSessionAlertsEnabled || $event->isFirstSession) {
            return;
        }

        $locale->setDefault($event->locale);

        $user = new Document($event->user);
        $project = new Document($event->project);
        $session = new Document($event->session);

        $subject = $locale->getText("emails.sessionAlert.subject");
        $preview = $locale->getText("emails.sessionAlert.preview");
        $customTemplate = $project->getAttribute('templates', [])['email.sessionAlert-' . $event->locale] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new \Exception('Invalid template path');
        }

        $bodyTemplate = __DIR__ . '/../../../../app/config/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $message = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-session-alert.tpl');
        $message
            ->setParam('{{hello}}', $locale->getText("emails.sessionAlert.hello"))
            ->setParam('{{body}}', $locale->getText("emails.sessionAlert.body"))
            ->setParam('{{listDevice}}', $locale->getText("emails.sessionAlert.listDevice"))
            ->setParam('{{listIpAddress}}', $locale->getText("emails.sessionAlert.listIpAddress"))
            ->setParam('{{listCountry}}', $locale->getText("emails.sessionAlert.listCountry"))
            ->setParam('{{footer}}', $locale->getText("emails.sessionAlert.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.sessionAlert.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.sessionAlert.signature"));

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        $queueForMails = new Mail($publisher);

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $queueForMails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $queueForMails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $clientName = $session->getAttribute('clientName');
        if (empty($clientName)) {
            $userAgent = $session->getAttribute('userAgent');
            $clientName = !empty($userAgent) ? $userAgent : 'UNKNOWN';
            $session->setAttribute('clientName', $clientName);
        }

        $projectName = $project->getAttribute('name');
        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            'date' => (new \DateTime())->format('F j'),
            'year' => (new \DateTime())->format('YYYY'),
            'time' => (new \DateTime())->format('H:i:s'),
            'user' => $user->getAttribute('name'),
            'project' => $projectName,
            'device' => $session->getAttribute('clientName'),
            'ipAddress' => $session->getAttribute('ip'),
            'country' => $locale->getText('countries.' . $session->getAttribute('countryCode'), $locale->getText('locale.country.unknown')),
        ];

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $emailVariables = array_merge($emailVariables, [
                'accentColor' => $platform['accentColor'],
                'logoUrl' => $platform['logoUrl'],
                'twitter' => $platform['twitterUrl'],
                'discord' => $platform['discordUrl'],
                'github' => $platform['githubUrl'],
                'terms' => $platform['termsUrl'],
                'privacy' => $platform['privacyUrl'],
                'platform' => $platform['platformName'],
            ]);
        }

        $queueForMails
            ->setSubject($subject)
            ->setPreview($preview)
            ->setBody($body)
            ->setBodyTemplate($bodyTemplate)
            ->appendVariables($emailVariables)
            ->setRecipient($user->getAttribute('email'));

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $queueForMails->setSenderName($platform['emailSenderName']);
        }

        $queueForMails->trigger();
    }
}

<?php

namespace Appwrite\Messaging\Adapter\Email;

use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Messaging\Adapter\Email\SMTP as UtopiaSMTP;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

/**
 * The SMTP adapter, dialling each server on an address the caller checked rather than
 * resolving the server name again, so DNS cannot answer differently between the check
 * and the connection. The certificate is still verified against the server name.
 */
class SMTP extends UtopiaSMTP
{
    private ?PHPMailer $session = null;

    /**
     * @param list<array{host: string, peer: string}> $servers Each server entry rewritten to its checked address, with the name to verify its certificate against, tried in order.
     */
    public function __construct(
        private readonly array $servers,
        private readonly int $port = 25,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $smtpSecure = '',
        private readonly bool $smtpAutoTLS = false,
        private readonly string $xMailer = '',
        private readonly int $timeout = 30,
        private readonly bool $keepAlive = false,
        private readonly int $timelimit = 30,
    ) {
        if ($servers === []) {
            throw new \InvalidArgumentException('No SMTP server to connect to.');
        }

        parent::__construct(
            host: $servers[0]['host'],
            port: $port,
            username: $username,
            password: $password,
            smtpSecure: $smtpSecure,
            smtpAutoTLS: $smtpAutoTLS,
            xMailer: $xMailer,
            timeout: $timeout,
            keepAlive: $keepAlive,
            timelimit: $timelimit,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function process(EmailMessage $message): array
    {
        $response = new Response($this->getType());

        if ($this->keepAlive && $this->session !== null) {
            $mail = $this->session;
            $mail->clearAllRecipients();
            $mail->clearReplyTos();
            $mail->clearAttachments();
        } else {
            $mail = new PHPMailer();
            $mail->isSMTP();
            $mail->Port = $this->port;
            $mail->SMTPAuth = !empty($this->username) && !empty($this->password);
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = $this->smtpSecure;
            $mail->SMTPAutoTLS = $this->smtpAutoTLS;
            $mail->Timeout = $this->timeout;
            $mail->SMTPKeepAlive = $this->keepAlive;

            if ($this->keepAlive) {
                $this->session = $mail;
            }
        }

        $mail->XMailer = $this->xMailer;
        $mail->CharSet = 'UTF-8';
        $mail->getSMTPInstance()->Timelimit = $this->timelimit;
        $mail->Subject = $message->getSubject();
        $mail->Body = $message->getContent();
        $mail->setFrom($message->getFromEmail(), $message->getFromName());
        $mail->addReplyTo($message->getReplyToEmail(), $message->getReplyToName());
        $mail->isHTML($message->isHtml());

        // Strip tags misses style tags, so we use regex to remove them
        $mail->AltBody = \preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $mail->Body);
        $mail->AltBody = \strip_tags($mail->AltBody);
        $mail->AltBody = \trim($mail->AltBody);

        foreach ($message->getTo() as $to) {
            $mail->addAddress($to['email'], $to['name'] ?? '');
        }

        foreach ($message->getCC() ?? [] as $cc) {
            $mail->addCC($cc['email'], $cc['name'] ?? '');
        }

        foreach ($message->getBCC() ?? [] as $bcc) {
            $mail->addBCC($bcc['email'], $bcc['name'] ?? '');
        }

        if (!empty($message->getAttachments())) {
            $size = 0;

            foreach ($message->getAttachments() as $attachment) {
                $size += $attachment->getContent() !== null
                    ? \strlen($attachment->getContent())
                    : \filesize($attachment->getPath());
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Attachments size exceeds the maximum allowed size of 25MB');
            }

            foreach ($message->getAttachments() as $attachment) {
                $mail->addStringAttachment(
                    string: $attachment->getContent() ?? \file_get_contents($attachment->getPath()),
                    filename: $attachment->getName(),
                    encoding: PHPMailer::ENCODING_BASE64,
                    type: $attachment->getType()
                );
            }
        }

        // Like PHPMailer with a server list, the first server that accepts the session is used
        foreach ($this->servers as $server) {
            $mail->Host = $server['host'];
            $mail->SMTPOptions = ['ssl' => ['peer_name' => $server['peer']]];

            if ($mail->smtpConnect()) {
                break;
            }
        }

        $sent = $mail->send();

        if ($sent) {
            $totalDelivered = \count($message->getTo()) + \count($message->getCC() ?: []) + \count($message->getBCC() ?: []);
            $response->setDeliveredTo($totalDelivered);
        }

        $error = empty($mail->ErrorInfo) ? 'Unknown error' : $mail->ErrorInfo;

        foreach ([...$message->getTo(), ...($message->getCC() ?? []), ...($message->getBCC() ?? [])] as $recipient) {
            $response->addResult($recipient['email'], $sent ? '' : $error);
        }

        return $response->toArray();
    }
}

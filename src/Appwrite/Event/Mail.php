<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Mail extends Event
{
    protected string $recipient = '';
    protected string $name = '';
    protected string $subject = '';
    protected string $body = '';
    protected array $smtp = [];
    protected array $variables = [];
    protected string $bodyTemplate = '';
    protected array $attachment = [];

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::MAILS_QUEUE_NAME)
            ->setClass(Event::MAILS_CLASS_NAME);
    }

    /**
     * Sets subject for the mail event.
     *
     * @param string $subject
     * @return self
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Returns subject for the mail event.
     *
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * Sets recipient for the mail event.
     *
     * @param string $recipient
     * @return self
     */
    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Returns set recipient for mail event.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * Sets body for the mail event.
     *
     * @param string $body
     * @return self
     */
    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Returns body for the mail event.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Sets name for the mail event.
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns set name for the mail event.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets bodyTemplate for the mail event.
     *
     * @param string $bodyTemplate
     * @return self
     */
    public function setbodyTemplate(string $bodyTemplate): self
    {
        $this->bodyTemplate = $bodyTemplate;

        return $this;
    }

    /**
     * Returns subject for the mail event.
     *
     * @return string
     */
    public function getbodyTemplate(): string
    {
        return $this->bodyTemplate;
    }

    /**
     * Set SMTP Host
     *
     * @param string $host
     * @return self
     */
    public function setSmtpHost(string $host): self
    {
        $this->smtp['host'] = $host;
        return $this;
    }

    /**
     * Set SMTP port
     *
     * @param int port
     * @return self
     */
    public function setSmtpPort(int $port): self
    {
        $this->smtp['port'] = $port;
        return $this;
    }

    /**
     * Set SMTP username
     *
     * @param string $username
     * @return self
     */
    public function setSmtpUsername(string $username): self
    {
        $this->smtp['username'] = $username;
        return $this;
    }

    /**
     * Set SMTP password
     *
     * @param string $password
     * @return self
     */
    public function setSmtpPassword(string $password): self
    {
        $this->smtp['password'] = $password;
        return $this;
    }

    /**
     * Set SMTP secure
     *
     * @param string $password
     * @return self
     */
    public function setSmtpSecure(string $secure): self
    {
        $this->smtp['secure'] = $secure;
        return $this;
    }

    /**
     * Set SMTP sender email
     *
     * @param string $senderEmail
     * @return self
     */
    public function setSmtpSenderEmail(string $senderEmail): self
    {
        $this->smtp['senderEmail'] = $senderEmail;
        return $this;
    }

    /**
     * Set SMTP sender name
     *
     * @param string $senderName
     * @return self
     */
    public function setSmtpSenderName(string $senderName): self
    {
        $this->smtp['senderName'] = $senderName;
        return $this;
    }

    /**
     * Set SMTP reply to
     *
     * @param string $replyTo
     * @return self
     */
    public function setSmtpReplyTo(string $replyTo): self
    {
        $this->smtp['replyTo'] = $replyTo;
        return $this;
    }

    /**
     * Get SMTP
     *
     * @return string
     */
    public function getSmtpHost(): string
    {
        return $this->smtp['host'] ?? '';
    }

    /**
     * Get SMTP port
     *
     * @return integer
     */
    public function getSmtpPort(): int
    {
        return $this->smtp['port'] ?? 0;
    }

    /**
     * Get SMTP username
     *
     * @return string
     */
    public function getSmtpUsername(): string
    {
        return $this->smtp['username'] ?? '';
    }

    /**
     * Get SMTP password
     *
     * @return string
     */
    public function getSmtpPassword(): string
    {
        return $this->smtp['password'] ?? '';
    }

    /**
     * Get SMTP secure
     *
     * @return string
     */
    public function getSmtpSecure(): string
    {
        return $this->smtp['secure'] ?? '';
    }

    /**
     * Get SMTP sender email
     *
     * @return string
     */
    public function getSmtpSenderEmail(): string
    {
        return $this->smtp['senderEmail'] ?? '';
    }

    /**
     * Get SMTP sender name
     *
     * @return string
     */
    public function getSmtpSenderName(): string
    {
        return $this->smtp['senderName'] ?? '';
    }

    /**
     * Get SMTP reply to
     *
     * @return string
     */
    public function getSmtpReplyTo(): string
    {
        return $this->smtp['replyTo'] ?? '';
    }

    /**
     * Get Email Variables
     *
     * @return array
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Set Email Variables
     *
     * @param array $variables
     * @return self
     */
    public function setVariables(array $variables): self
    {
        $this->variables = $variables;
        return $this;
    }

    /**
     * Set attachment
     * @param string $content
     * @param string $filename
     * @param string $encoding
     * @param string $type
     * @return self
     */
    public function setAttachment(string $content, string $filename, string $encoding = 'base64', string $type = 'plain/text')
    {
        $this->attachment = [
            'content' => base64_encode($content),
            'filename' => $filename,
            'encoding' => $encoding,
            'type' => $type,
        ];
        return $this;
    }

    /**
     * Get attachment
     *
     * @return array
     */
    public function getAttachment(): array
    {
        return $this->attachment;
    }

    /**
     * Reset attachment
     *
     * @return self
     */
    public function resetAttachment(): self
    {
        $this->attachment = [];
        return $this;
    }

    /**
     * Reset
     *
     * @return self
     */
    public function reset(): self
    {
        $this->project = null;
        $this->recipient = '';
        $this->name = '';
        $this->subject = '';
        $this->body = '';
        $this->variables = [];
        $this->bodyTemplate = '';
        $this->attachment = [];
        return $this;
    }

    /**
     * Executes the event and sends it to the mails worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->project,
            'recipient' => $this->recipient,
            'name' => $this->name,
            'subject' => $this->subject,
            'bodyTemplate' => $this->bodyTemplate,
            'body' => $this->body,
            'smtp' => $this->smtp,
            'variables' => $this->variables,
            'attachment' => $this->attachment,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}

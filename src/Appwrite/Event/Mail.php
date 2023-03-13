<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Mail extends Event
{
    protected string $recipient = '';
    protected string $from = '';
    protected string $name = '';
    protected string $subject = '';
    protected string $body = '';
    protected array $smtp = [];

    public function __construct()
    {
        parent::__construct(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME);
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
     * Sets from for the mail event.
     *
     * @param string $from
     * @return self
     */
    public function setFrom(string $from): self
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Returns from for mail event.
     *
     * @return string
     */
    public function getFrom(): string
    {
        return $this->from;
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
        $this->smtp['username'];
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
        $this->smtp['password'];
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
     * Get SMTP sender name
     *
     * @return string
     */
    public function getSmtpSenderName(): string
    {
        return $this->smtp['senderName'] ?? '';
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
     * Get SMTP reply to
     *
     * @return string
     */
    public function getSmtpReplyTo(): string
    {
        return $this->smtp['replyTo'] ?? '';
    }

    /**
     * Executes the event and sends it to the mails worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'from' => $this->from,
            'recipient' => $this->recipient,
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $this->body,
            'smtp' => $this->smtp,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}

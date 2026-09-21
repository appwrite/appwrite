<?php

declare(strict_types=1);

namespace Appwrite\Auth\Validator;

use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Validator;
use Utopia\Validator\WhiteList;

final class EmailWhitelist extends Validator
{
    private EmailValidator $validator;

    private WhiteList $emails;

    private WhiteList $domains;

    /**
     * @param array<mixed> $emails
     */
    public function __construct(array $emails)
    {
        $allowedEmails = [];
        $allowedDomains = [];

        foreach ($emails as $email) {
            if (!\is_string($email)) {
                continue;
            }

            $email = \trim($email);

            if (!\str_contains($email, '*')) {
                $allowedEmails[] = $email;
                continue;
            }

            if (\str_starts_with($email, '*@') && \substr_count($email, '*') === 1) {
                $allowedDomains[] = \substr($email, 2);
            }
        }

        $this->validator = new EmailValidator();
        $this->emails = new WhiteList($allowedEmails);
        $this->domains = new WhiteList($allowedDomains);
    }

    public function getDescription(): string
    {
        return 'Email must match an allowed email address or domain';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        if (!\is_string($value) || !$this->validator->isValid($value)) {
            return false;
        }

        $email = new Email($value);

        return $this->emails->isValid($email->get()) || $this->domains->isValid($email->getDomain());
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}

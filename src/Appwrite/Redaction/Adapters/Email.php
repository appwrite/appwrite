<?php

namespace Appwrite\Redaction\Adapters;

use Appwrite\Redaction\Exceptions\Redaction;

final class Email implements Adapter
{
    private bool $redactUser = true;
    private bool $redactDomain = true;
    private bool $redactTld = false;

    public function setRedactUser(bool $value): self
    {
        $this->redactUser = $value;
        return $this;
    }

    public function setRedactDomain(bool $value): self
    {
        $this->redactDomain = $value;
        return $this;
    }

    public function setRedactTLD(bool $value): self
    {
        $this->redactTld = $value;
        return $this;
    }

    /**
     * Example:
     *   john.doe@example.com
     *   redactUser=true, redactDomain=true, redactTld=false
     *   => j******e@e******.com
     *
     * Behavior aims to mirror your previous helpers while adding toggles.
     */
    public function redact(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Redaction('Invalid email value for redaction.');
        }

        [$user, $domain] = explode('@', $email, 2);

        // Split domain into "label(s)" + TLD (by last dot)
        $lastDot = strrpos($domain, '.');
        if ($lastDot === false) {
            $domainLabels = $domain;
            $tld = '';
        } else {
            $domainLabels = substr($domain, 0, $lastDot); // "example" or "sub.example"
            $tld = substr($domain, $lastDot + 1);        // "com" (or "uk")
        }

        $maskedUser = $user;
        if ($this->redactUser) {
            $maskedUser = self::maskMiddle($user);
        }

        $maskedLabels = $domainLabels;
        if ($this->redactDomain) {
            // Mask leftmost label only (keeps subdomains readable), similar to the old helper
            $parts = explode('.', $domainLabels);
            if (!empty($parts[0])) {
                $parts[0] = self::maskStart($parts[0]);
            }
            $maskedLabels = implode('.', $parts);
        }

        $maskedTld = $tld;
        if ($this->redactTld && $tld !== '') {
            $maskedTld = str_repeat('*', strlen($tld));
        }

        $maskedDomain = $maskedLabels . ($tld !== '' ? '.' . $maskedTld : '');

        return $maskedUser . '@' . $maskedDomain;
    }

    private static function maskMiddle(string $s): string
    {
        $len = strlen($s);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        return $s[0] . str_repeat('*', $len - 2) . $s[$len - 1];
    }

    private static function maskStart(string $s): string
    {
        $len = strlen($s);
        if ($len <= 1) {
            return '*';
        }

        return $s[0] . str_repeat('*', $len - 1);
    }
}

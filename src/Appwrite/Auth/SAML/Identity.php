<?php

namespace Appwrite\Auth\SAML;

/**
 * The identity carried by a validated SAML assertion, reduced to the values
 * the shared session pipeline consumes.
 *
 * Only construct this from a Response that has already passed validate() --
 * everything here is read straight out of the assertion.
 */
class Identity
{
    /**
     * @param string $id
     * @param string $email
     * @param string $name
     */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $name,
    ) {
    }

    /**
     * Build an identity from a validated assertion.
     *
     * The NameID is the provider UID: it is the only value SAML guarantees,
     * and unlike email it is stable when a user is renamed at the IdP.
     *
     * Email has to come from an attribute statement, because a NameID is often
     * an opaque persistent identifier rather than an address. Appwrite requires
     * an email to create a user, so a missing one is a configuration error and
     * the message says how to fix it.
     *
     * @param Response $response
     * @param Settings $settings
     *
     * @return self
     *
     * @throws Exception when the assertion carries no NameID or no usable email.
     */
    public static function fromResponse(Response $response, Settings $settings): self
    {
        $nameId = $response->getNameId();

        if ($nameId === '') {
            throw new Exception('SAML assertion does not contain a NameID.');
        }

        $attributes = $response->getAttributes();

        $email = self::claim($attributes, $settings->getAttributeCandidates('email'));

        // A NameID in emailAddress format is itself an address, so fall back to
        // it before giving up.
        if ($email === '' && \filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            $email = $nameId;
        }

        if ($email === '') {
            throw new Exception('SAML assertion does not contain an email address. Configure the identity provider to release an email attribute (for example "email") in its attribute statement.');
        }

        if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('SAML assertion contains an email attribute that is not a valid email address.');
        }

        return new self($nameId, $email, self::resolveName($attributes, $settings));
    }

    /**
     * Display name, preferring a single name attribute and falling back to
     * first and last name. An empty result is fine: the account pipeline treats
     * the name as optional.
     *
     * @param array<string, array<int, string>> $attributes
     * @param Settings $settings
     *
     * @return string
     */
    private static function resolveName(array $attributes, Settings $settings): string
    {
        $name = self::claim($attributes, $settings->getAttributeCandidates('name'));

        if ($name !== '') {
            return $name;
        }

        $parts = \array_filter([
            self::claim($attributes, $settings->getAttributeCandidates('firstName')),
            self::claim($attributes, $settings->getAttributeCandidates('lastName')),
        ]);

        return \implode(' ', $parts);
    }

    /**
     * First non-empty value among the candidate attribute names.
     *
     * @param array<string, array<int, string>> $attributes
     * @param array<int, string> $candidates
     *
     * @return string
     */
    private static function claim(array $attributes, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            foreach ($attributes[$candidate] ?? [] as $value) {
                if (\trim($value) !== '') {
                    return \trim($value);
                }
            }
        }

        return '';
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}

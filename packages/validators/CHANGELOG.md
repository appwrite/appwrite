# Changelog

All notable changes to `utopia-php/validators` are documented in this file.

## Unreleased

### Added

- New `Subnet` validator. Accepts an IPv4 or IPv6 address inside a CIDR range
  (`10.0.0.0/8`, `fd00::/8`), or equal to a single address when the range has
  no prefix length. Host bits in the range are masked, and an address of the
  other family never matches, so `::ffff:10.0.0.5` is outside `10.0.0.0/8`.
  The constructor throws `InvalidArgumentException` for a range that is not an
  IP address with an optional decimal prefix length.
- New `Allowlist` validator. Accepts a listed hostname, compared exactly after
  lowercasing and removing one trailing dot (no wildcard or suffix matching),
  or an IP address (optionally in brackets) inside one of the listed `Subnet`s.
  `hasHostname()`, `hasAddress()` and `hasSubnets()` expose each half for
  callers that check names and resolved addresses separately.

## 0.4.2

### Added

- New `Identifier` validator. Extends `Text` and additionally requires a C-style
  identifier (`^[A-Za-z_][A-Za-z0-9_]*$`): letters, digits and underscores only,
  not starting with a digit. This is the shape required of environment variable
  names and shell identifiers, so a value that passes a plain length check but
  contains a tab, a space or an accented letter is rejected. Accepts an optional
  `int $length` (0 = unlimited); minimum length is 1.

## 0.4.0

### Phone validator

#### Added

- New `Phone` validator for E.164 phone number syntax. It validates values with a
  leading `+` followed by 7 to 15 digits, supports optional empty strings through
  `allowEmpty`, and can opt in to URL/path recovery through `normalize`.

## 0.3.2

### JSON shape validators

#### Added

- New `JSON\ObjectValidator` and `JSON\ArrayValidator` validators for JSON shape checks.
  Both accept decoded values or encoded JSON strings while preserving the distinction between
  objects and arrays.

## 0.3.1

### URL validator — OAuth2 secure-redirect transport policy

#### Added

- New optional constructor parameter `bool $httpsOrLoopback = false` (kept last
  in the signature). When enabled, a standard (authority-bearing, non-private-use)
  URL is valid only if its scheme is `https` on any host, or `http` on a loopback
  host (`localhost`, `127.0.0.1`, or `[::1]`); every other standard scheme and any
  routable `http` host is rejected (RFC 8252 §7.3). Private-use scheme URIs
  (governed by `allowPrivateUseSchemes`) are exempt. The flag is self-contained and
  independent of `allowedSchemes` — when both are set, a value must satisfy both.
  `getDescription()` reflects the restriction when the flag is on.

The change is backward compatible: `httpsOrLoopback` defaults to `false`, so
existing callers are unaffected, and the behavior of `allowedSchemes` and
`allowPrivateUseSchemes` (including how `allowedSchemes` restricts which
private-use schemes are accepted) is unchanged.

/**
 * LDAP settings logic that needs no API: default ports, the user filter
 * template and reading pasted ldap:// and ldaps:// URLs.
 */

export type LdapEncryption = 'none' | 'ldaps' | 'starttls'

/** The port a sign-in uses when none is chosen: 636 for LDAPS, 389 otherwise. */
export function ldapDefaultPort(encryption: LdapEncryption): number {
  return encryption === 'ldaps' ? 636 : 389
}

/** Replaced with what the person signing in types. */
export const LDAP_USERNAME_PLACEHOLDER = '{username}'

/** The user filter the API uses when it is left empty. */
export const LDAP_USER_FILTER_DEFAULT = `(uid=${LDAP_USERNAME_PLACEHOLDER})`

/** An empty user filter means the default; any other has to say where the username goes. */
export function isLdapUserFilterValid(filter: string): boolean {
  const value = filter.trim()
  return value === '' || value.includes(LDAP_USERNAME_PLACEHOLDER)
}

/** What a pasted LDAP URL says about the connection. */
export type LdapUrlSettings = {
  /** The host of every URL, in order and without repeats. IPv6 addresses come without brackets. */
  hosts: string[]
  encryption: LdapEncryption
  /** The first URL's port, or empty when it is the default port of `encryption`. */
  port: string
  /** The first DN given as a URL path, or empty. */
  baseDn: string
}

/** scheme, host (a bracketed IPv6 address or a name), optional port, optional DN path, then ?attributes?scope?filter. */
const LDAP_URL =
  /^(ldaps?):\/\/(\[[^\]/?#]*\]|[^/:?#[\]]*)(?::(\d*))?(?:\/([^?#]*))?(?:[?#].*)?$/i

const LDAP_URL_START = /^ldaps?:\/\//i

function decode(value: string): string {
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}

/**
 * Read the settings of LDAP URLs such as ldaps://dc1.corp.example:636/dc=corp,dc=example,
 * one or several separated by spaces, commas or new lines. Returns null when the text
 * holds no LDAP URL with a host, so it pastes as usual.
 *
 * Encryption and port come from the first URL. A URL cannot say StartTLS, so ldap://
 * keeps StartTLS when it is the current encryption and means None otherwise.
 */
export function ldapSettingsFromUrls(
  text: string,
  current: LdapEncryption,
): LdapUrlSettings | null {
  // A DN holds commas too, so a comma only separates URLs when another URL follows it.
  const tokens = text
    .split(/\s+/)
    .flatMap((part) => part.split(/,(?=ldaps?:\/\/)/i))
    .map((token) => token.replace(/^,+|,+$/g, ''))
    .filter((token) => LDAP_URL_START.test(token))

  let settings: LdapUrlSettings | null = null
  for (const token of tokens) {
    const match = LDAP_URL.exec(token)
    if (!match) continue
    const [, scheme, rawHost, rawPort = '', rawDn = ''] = match
    const host = rawHost.replace(/^\[(.*)\]$/, '$1')
    if (host === '') continue
    const baseDn = decode(rawDn).trim()

    if (!settings) {
      const encryption: LdapEncryption =
        scheme.toLowerCase() === 'ldaps'
          ? 'ldaps'
          : current === 'starttls'
            ? 'starttls'
            : 'none'
      const port =
        rawPort === '' ? ldapDefaultPort(encryption) : Number(rawPort)
      settings = {
        hosts: [],
        encryption,
        port: port === ldapDefaultPort(encryption) ? '' : String(port),
        baseDn,
      }
    } else if (settings.baseDn === '') {
      settings.baseDn = baseDn
    }
    if (!settings.hosts.includes(host)) settings.hosts.push(host)
  }
  return settings
}

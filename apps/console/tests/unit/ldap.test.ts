import { describe, expect, test } from 'bun:test'
import { isLdapUserFilterValid, ldapSettingsFromUrls } from '@/lib/ldap'

describe('ldapSettingsFromUrls', () => {
  test('reads host, encryption and base DN, and leaves a default port empty', () => {
    expect(
      ldapSettingsFromUrls(
        'ldaps://dc1.corp.example:636/dc=corp,dc=example',
        'ldaps',
      ),
    ).toEqual({
      hosts: ['dc1.corp.example'],
      encryption: 'ldaps',
      port: '',
      baseDn: 'dc=corp,dc=example',
    })
  })

  test('keeps a port that is not the default', () => {
    expect(
      ldapSettingsFromUrls('ldaps://dc1.corp.example:3269', 'none'),
    ).toMatchObject({ encryption: 'ldaps', port: '3269', baseDn: '' })
  })

  test('ldap:// means None, or keeps StartTLS when it is chosen', () => {
    expect(
      ldapSettingsFromUrls('ldap://dc1.corp.example', 'ldaps'),
    ).toMatchObject({ encryption: 'none', port: '' })
    expect(
      ldapSettingsFromUrls('ldap://dc1.corp.example:389', 'starttls'),
    ).toMatchObject({ encryption: 'starttls', port: '' })
    expect(
      ldapSettingsFromUrls('ldap://dc1.corp.example:636', 'starttls'),
    ).toMatchObject({ encryption: 'starttls', port: '636' })
  })

  test('reads the scheme in any case', () => {
    expect(
      ldapSettingsFromUrls('LDAPS://DC1.corp.example', 'none'),
    ).toMatchObject({ hosts: ['DC1.corp.example'], encryption: 'ldaps' })
  })

  test('splits several URLs on spaces, commas and new lines', () => {
    expect(
      ldapSettingsFromUrls(
        'ldaps://dc1.corp.example/dc=corp,dc=example, ldaps://dc2.corp.example\nldaps://dc3.corp.example,ldaps://dc1.corp.example',
        'ldaps',
      ),
    ).toEqual({
      hosts: ['dc1.corp.example', 'dc2.corp.example', 'dc3.corp.example'],
      encryption: 'ldaps',
      port: '',
      baseDn: 'dc=corp,dc=example',
    })
  })

  test('takes encryption and port from the first URL', () => {
    expect(
      ldapSettingsFromUrls(
        'ldap://dc1.corp.example:1389 ldaps://dc2.corp.example',
        'ldaps',
      ),
    ).toMatchObject({
      hosts: ['dc1.corp.example', 'dc2.corp.example'],
      encryption: 'none',
      port: '1389',
    })
  })

  test('takes the base DN from a later URL when the first has none', () => {
    expect(
      ldapSettingsFromUrls(
        'ldaps://dc1.corp.example ldaps://dc2.corp.example/dc=corp,dc=example',
        'ldaps',
      ),
    ).toMatchObject({ baseDn: 'dc=corp,dc=example' })
  })

  test('strips the brackets of an IPv6 address', () => {
    expect(
      ldapSettingsFromUrls('ldap://[2001:db8::1]:10389/dc=example', 'none'),
    ).toEqual({
      hosts: ['2001:db8::1'],
      encryption: 'none',
      port: '10389',
      baseDn: 'dc=example',
    })
  })

  test('decodes the DN and drops attributes, scope and filter', () => {
    expect(
      ldapSettingsFromUrls(
        'ldaps://dc1.corp.example/ou=Domain%20Users,dc=corp,dc=example?cn?sub?(objectClass=person)',
        'ldaps',
      ),
    ).toMatchObject({ baseDn: 'ou=Domain Users,dc=corp,dc=example' })
  })

  test('returns null for text without an LDAP URL', () => {
    expect(ldapSettingsFromUrls('dc1.corp.example', 'ldaps')).toBeNull()
    expect(
      ldapSettingsFromUrls('dc1.corp.example, 2001:db8::1', 'ldaps'),
    ).toBeNull()
    expect(
      ldapSettingsFromUrls('https://dc1.corp.example/dc=corp', 'ldaps'),
    ).toBeNull()
    expect(
      ldapSettingsFromUrls('ldap:///dc=corp,dc=example', 'ldaps'),
    ).toBeNull()
  })
})

describe('isLdapUserFilterValid', () => {
  test('needs {username} unless the filter is empty', () => {
    expect(isLdapUserFilterValid('')).toBe(true)
    expect(isLdapUserFilterValid('  ')).toBe(true)
    expect(isLdapUserFilterValid('(sAMAccountName={username})')).toBe(true)
    expect(isLdapUserFilterValid('(|(uid={username})(mail={username}))')).toBe(
      true,
    )
    expect(isLdapUserFilterValid('(objectClass=person)')).toBe(false)
    expect(isLdapUserFilterValid('(uid={USERNAME})')).toBe(false)
  })
})

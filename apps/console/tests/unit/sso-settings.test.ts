import { describe, expect, test } from 'bun:test'
import { getEmailDomainRecord } from '@/components/pages/organizations/$orgId/settings/_components/VerifyEmailDomain'
import {
  canShowOrgSsoSettings,
  getFirstAllowedOrgSettingsPath,
} from '@/lib/console-access-checks'
import { deriveAccessFromRolesScopes } from '@/lib/console-roles'
import {
  getVerifiedEmailDomains,
  isInternalEmail,
} from '@/lib/email-domain-affiliation'

const owner = deriveAccessFromRolesScopes(
  ['owner'],
  ['teams.read', 'teams.write'],
)
const developer = deriveAccessFromRolesScopes(['developer'], ['teams.read'])

describe('canShowOrgSsoSettings', () => {
  test('shows Single sign-on to owners once the experiment is on', () => {
    expect(canShowOrgSsoSettings(owner, { orgRoles: true, sso: true })).toBe(
      true,
    )
  })

  test('hides it from everyone else, as only owners decide who joins', () => {
    expect(
      canShowOrgSsoSettings(developer, { orgRoles: true, sso: true }),
    ).toBe(false)
  })

  test('hides it while the experiment is off, even from owners', () => {
    expect(canShowOrgSsoSettings(owner, { orgRoles: true, sso: false })).toBe(
      false,
    )
    expect(canShowOrgSsoSettings(owner, { orgRoles: true })).toBe(false)
  })

  test('a developer left with no other settings tab is not sent to it', () => {
    const access = deriveAccessFromRolesScopes(['developer'], [])
    expect(
      getFirstAllowedOrgSettingsPath(
        access,
        { orgRoles: true, sso: true },
        '/settings',
      ),
    ).not.toBe('/settings/sso')
  })
})

describe('getEmailDomainRecord', () => {
  test('names the record at _appwrite, relative to the registrable domain', () => {
    expect(getEmailDomainRecord('acme.com', 'org1')).toMatchObject({
      type: 'TXT',
      name: '_appwrite',
      value: 'team:org1',
    })
  })

  test('keeps the labels in front of the registrable domain', () => {
    expect(getEmailDomainRecord('devs.mycompany.com', 'org1').name).toBe(
      '_appwrite.devs',
    )
    expect(getEmailDomainRecord('mail.example.co.uk', 'org1').name).toBe(
      '_appwrite.mail',
    )
  })
})

describe('isInternalEmail', () => {
  const verified = getVerifiedEmailDomains([
    { domain: 'MyCompany.com', status: 'verified' },
    { domain: 'pending.com', status: 'unverified' },
  ])

  test('a member on a verified domain is internal', () => {
    expect(isInternalEmail('Jay@mycompany.COM', verified)).toBe(true)
  })

  test('other domains, unverified domains and subdomains are external', () => {
    expect(isInternalEmail('jay@gmail.com', verified)).toBe(false)
    expect(isInternalEmail('jay@pending.com', verified)).toBe(false)
    expect(isInternalEmail('jay@dev.mycompany.com', verified)).toBe(false)
    expect(isInternalEmail('', verified)).toBe(false)
  })
})

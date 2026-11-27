import { describe, expect, test } from 'bun:test'
import type { Client } from '@appwrite.io/console'
import { ssoCallbackURL, TeamSSO } from '@/lib/appwrite/team-sso'
import {
  getSSODomainRecord,
  ssoQueryKey,
  ssoTargetTeamId,
  ssoTeamQueryKey,
  teamRoleError,
  type SSOTarget,
} from '@/lib/sso-target'

const ENDPOINT = 'https://fra.cloud.appwrite.io/v1'
const organization: SSOTarget = {
  scope: 'organization',
  organizationId: 'acme',
}
const team: SSOTarget = {
  scope: 'project',
  projectId: 'storefront',
  teamId: 'sales',
}

function clientFor(project: string): Client {
  return { config: { endpoint: ENDPOINT, project } } as unknown as Client
}

describe('the single sign-on redirect URL', () => {
  test("is the Console's callback on the Console's client", () => {
    expect(ssoCallbackURL(ENDPOINT, 'console')).toBe(
      `${ENDPOINT}/account/sessions/sso/callback`,
    )
    expect(new TeamSSO(clientFor('console')).redirectURL()).toBe(
      `${ENDPOINT}/account/sessions/sso/callback`,
    )
  })

  test("carries the project's ID on a project's client", () => {
    expect(ssoCallbackURL(ENDPOINT, 'storefront')).toBe(
      `${ENDPOINT}/account/sessions/sso/callback/storefront`,
    )
    expect(new TeamSSO(clientFor('storefront')).redirectURL()).toBe(
      `${ENDPOINT}/account/sessions/sso/callback/storefront`,
    )
  })
})

describe('single sign-on query keys', () => {
  test('keep the keys an organization always had', () => {
    expect(ssoQueryKey('emailDomains', organization)).toEqual([
      'emailDomains',
      'organization',
      'acme',
    ])
    expect(ssoQueryKey('ssoProviders', organization)).toEqual([
      'ssoProviders',
      'organization',
      'acme',
    ])
    expect(ssoTeamQueryKey(organization)).toEqual(['organization', 'acme'])
  })

  test("key a project's team by the project and the team, apart from an organization", () => {
    expect(ssoQueryKey('emailDomains', team)).toEqual([
      'emailDomains',
      'project',
      'storefront',
      'sales',
    ])
    expect(ssoTeamQueryKey(team)).toEqual([
      'team',
      'project',
      'storefront',
      'sales',
    ])
    // A team whose ID matches an organization's never shares its cache
    const twin: SSOTarget = {
      scope: 'project',
      projectId: 'storefront',
      teamId: 'acme',
    }
    expect(ssoQueryKey('emailDomains', twin)).not.toEqual(
      ssoQueryKey('emailDomains', organization),
    )
    expect(ssoQueryKey('ssoProviders', team)).not.toEqual(
      ssoQueryKey('ssoProviders', {
        scope: 'project',
        projectId: 'checkout',
        teamId: 'sales',
      }),
    )
  })

  test('call the routes on the organization, or on the team', () => {
    expect(ssoTargetTeamId(organization)).toBe('acme')
    expect(ssoTargetTeamId(team)).toBe('sales')
  })
})

describe('teamRoleError', () => {
  test('takes letters, digits, periods, hyphens and underscores', () => {
    expect(teamRoleError('admin')).toBeNull()
    expect(teamRoleError('Sales.EU-west_2')).toBeNull()
    expect(teamRoleError('a'.repeat(36))).toBeNull()
  })

  test('refuses what a membership role cannot be', () => {
    expect(teamRoleError('')).toBe('empty')
    expect(teamRoleError('   ')).toBe('empty')
    expect(teamRoleError('a'.repeat(37))).toBe('length')
    expect(teamRoleError('team lead')).toBe('format')
    expect(teamRoleError('admin!')).toBe('format')
    expect(teamRoleError('řidič')).toBe('format')
    expect(teamRoleError('-admin')).toBe('format')
    expect(teamRoleError('_admin')).toBe('format')
    expect(teamRoleError('.admin')).toBe('format')
  })
})

describe('getSSODomainRecord', () => {
  test("shows the API's record relative to the zone, the way DNS providers ask for it", () => {
    expect(
      getSSODomainRecord({
        domain: 'devs.acme.io',
        recordName: '_appwrite.devs.acme.io',
        recordValue: 'team:acme',
      }),
    ).toMatchObject({ type: 'TXT', name: '_appwrite.devs', value: 'team:acme' })
    expect(
      getSSODomainRecord({
        domain: 'acme.com',
        recordName: '_storefront.acme.com',
        recordValue: 'team:sales',
      }),
    ).toMatchObject({ name: '_storefront', value: 'team:sales' })
    expect(
      getSSODomainRecord({
        domain: 'mail.example.co.uk',
        recordName: '_storefront.mail.example.co.uk.',
        recordValue: 'x',
      }).name,
    ).toBe('_storefront.mail')
  })

  test('never builds a name: one outside the zone is shown as the API sent it', () => {
    expect(
      getSSODomainRecord({
        domain: 'acme.com',
        recordName: '_storefront.elsewhere.net',
        recordValue: 'team:sales',
      }).name,
    ).toBe('_storefront.elsewhere.net')
    expect(
      getSSODomainRecord({
        domain: 'acme.com',
        recordName: 'acme.com',
        recordValue: 'team:sales',
      }).name,
    ).toBe('@')
  })
})

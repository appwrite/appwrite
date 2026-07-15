import { describe, expect, it } from 'vitest'
import type { Models } from '@appwrite.io/console'
import { matchKnownOAuthClient } from '@/lib/oauth-known-clients'
import { groupConnectedApps } from '@/lib/react-query/hooks/account-applications'

function makeApp(overrides: Partial<Models.App>): Models.App {
  return {
    $id: 'app-id',
    $createdAt: '2026-07-01T00:00:00.000+00:00',
    $updatedAt: '2026-07-01T00:00:00.000+00:00',
    name: '',
    description: '',
    clientUri: '',
    logoUri: '',
    privacyPolicyUrl: '',
    termsUrl: '',
    contacts: [],
    tagline: '',
    tags: [],
    images: [],
    supportUrl: '',
    dataDeletionUrl: '',
    redirectUris: [],
    postLogoutRedirectUris: [],
    enabled: true,
    type: 'public',
    deviceFlow: false,
    teamId: '',
    userId: '',
    secrets: [],
    ...overrides,
  }
}

function makeIdentity(overrides: Partial<Models.Identity>): Models.Identity {
  return {
    $id: 'identity-id',
    $createdAt: '2026-07-01T00:00:00.000+00:00',
    $updatedAt: '2026-07-01T00:00:00.000+00:00',
    userId: 'user',
    provider: 'oauth2:app-id',
    providerUid: '',
    providerEmail: '',
    providerAccessToken: '',
    providerAccessTokenExpiry: '',
    providerRefreshToken: '',
    scopes: [],
    ...overrides,
  } as Models.Identity
}

describe('matchKnownOAuthClient', () => {
  it('matches Claude Code with server-name suffix and loopback redirect', () => {
    const app = makeApp({
      name: 'Claude Code (appwrite)',
      redirectUris: ['http://localhost:63412/callback'],
    })
    expect(matchKnownOAuthClient(app)?.id).toBe('claude-code')
  })

  it('matches OpenCode', () => {
    const app = makeApp({
      name: 'OpenCode',
      redirectUris: ['http://127.0.0.1:19245/oauth/callback'],
    })
    expect(matchKnownOAuthClient(app)?.id).toBe('opencode')
  })

  it('rejects a name match whose URIs do not corroborate', () => {
    const app = makeApp({
      name: 'Claude Code',
      redirectUris: ['https://evil.example.com/callback'],
    })
    expect(matchKnownOAuthClient(app)).toBeNull()
  })

  it('matches on name alone when the API returns no URIs (stripped for non-managers)', () => {
    const app = makeApp({ name: 'Claude Code (appwrite)' })
    expect(matchKnownOAuthClient(app)?.id).toBe('claude-code')
  })

  it('matches Codex on name alone when URIs are unavailable', () => {
    const app = makeApp({ name: 'Codex' })
    expect(matchKnownOAuthClient(app)?.id).toBe('codex')
  })

  it('accepts client_uri as corroboration when there are no redirect URIs', () => {
    const app = makeApp({ name: 'OpenCode', clientUri: 'https://opencode.ai' })
    expect(matchKnownOAuthClient(app)?.id).toBe('opencode')
  })

  it('returns null for unknown clients', () => {
    const app = makeApp({ name: 'Some Random App' })
    expect(matchKnownOAuthClient(app)).toBeNull()
  })
})

describe('groupConnectedApps', () => {
  const claudeApp = (id: string) =>
    makeApp({
      $id: id,
      name: 'Claude Code (appwrite)',
      redirectUris: ['http://localhost:5000/callback'],
    })

  it('groups duplicate DCR registrations and sorts newest first', () => {
    const grants = [
      {
        identity: makeIdentity({
          $id: 'i1',
          $createdAt: '2026-07-01T00:00:00.000+00:00',
        }),
        appId: 'a1',
        app: claudeApp('a1'),
      },
      {
        identity: makeIdentity({
          $id: 'i2',
          $createdAt: '2026-07-10T00:00:00.000+00:00',
        }),
        appId: 'a2',
        app: claudeApp('a2'),
      },
      {
        identity: makeIdentity({
          $id: 'i3',
          $createdAt: '2026-07-05T00:00:00.000+00:00',
        }),
        appId: 'a3',
        app: makeApp({ $id: 'a3', name: 'Cursor', logoUri: 'https://cursor.com/logo.png' }),
      },
    ]

    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)

    // Groups ordered by most recent authorization.
    expect(groups[0].displayName).toBe('Claude Code (appwrite)')
    expect(groups[0].grants.map((g) => g.identity.$id)).toEqual(['i2', 'i1'])
    expect(groups[0].latestAuthorizedAt).toBe('2026-07-10T00:00:00.000+00:00')
    expect(groups[0].knownClient?.id).toBe('claude-code')

    expect(groups[1].displayName).toBe('Cursor')
    expect(groups[1].grants).toHaveLength(1)
  })

  it('keeps unresolved apps as their own rows keyed by app ID', () => {
    const grants = [
      { identity: makeIdentity({ $id: 'i1' }), appId: 'x1', app: null },
      { identity: makeIdentity({ $id: 'i2' }), appId: 'x2', app: null },
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)
    expect(groups.map((g) => g.displayName)).toEqual(['x1', 'x2'])
  })

  it('groups same-name apps even without a known-client match', () => {
    const grants = [
      {
        identity: makeIdentity({ $id: 'i1' }),
        appId: 'b1',
        app: makeApp({ $id: 'b1', name: 'My MCP Tool' }),
      },
      {
        identity: makeIdentity({ $id: 'i2' }),
        appId: 'b2',
        app: makeApp({ $id: 'b2', name: 'my mcp tool ' }),
      },
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(1)
    expect(groups[0].grants).toHaveLength(2)
  })
})

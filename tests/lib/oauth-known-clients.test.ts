import { describe, expect, it } from 'vitest'
import type { Models } from '@appwrite.io/console'
import { matchKnownOAuthClient } from '@/lib/oauth-known-clients'
import {
  groupConnectedApps,
  type AccountConnectedApp,
} from '@/lib/react-query/hooks/account-applications'

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
    labels: [],
    teamId: '',
    userId: '',
    secrets: [],
    ...overrides,
  }
}

function makeConsent(
  overrides: Partial<Models.Oauth2Consent>,
): Models.Oauth2Consent {
  return {
    $id: 'consent-id',
    $createdAt: '2026-07-01T00:00:00.000+00:00',
    $updatedAt: '2026-07-01T00:00:00.000+00:00',
    userId: 'user',
    appId: 'app-id',
    cimdUrl: '',
    scopes: [],
    resources: [],
    authorizationDetails: '[]',
    expire: '',
    ...overrides,
  }
}

function makeConnectedApp({
  consentId,
  createdAt,
  appId,
  cimdUrl,
  app,
}: {
  consentId: string
  createdAt?: string
  appId?: string
  cimdUrl?: string
  app: Models.App | null
}): AccountConnectedApp {
  return {
    consent: makeConsent({
      $id: consentId,
      $createdAt: createdAt ?? '2026-07-01T00:00:00.000+00:00',
      appId: appId ?? '',
      cimdUrl: cimdUrl ?? '',
    }),
    clientId: appId || cimdUrl || '',
    cimdUrl: cimdUrl || null,
    app,
  }
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

  it('accepts the CIMD URL as corroboration for a resolved CIMD client', () => {
    const app = makeApp({ name: 'Claude' })
    expect(
      matchKnownOAuthClient(app, 'https://claude.ai/.well-known/client.json')
        ?.id,
    ).toBe('claude')
  })

  it('rejects a CIMD client whose document host does not corroborate the name', () => {
    const app = makeApp({
      name: 'Claude',
      redirectUris: ['https://evil.example.com/callback'],
    })
    expect(
      matchKnownOAuthClient(app, 'https://evil.example.com/client.json'),
    ).toBeNull()
  })

  it('returns null without resolved metadata even when a CIMD URL is present', () => {
    expect(
      matchKnownOAuthClient(null, 'https://claude.ai/.well-known/client.json'),
    ).toBeNull()
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
      makeConnectedApp({
        consentId: 'c1',
        createdAt: '2026-07-01T00:00:00.000+00:00',
        appId: 'a1',
        app: claudeApp('a1'),
      }),
      makeConnectedApp({
        consentId: 'c2',
        createdAt: '2026-07-10T00:00:00.000+00:00',
        appId: 'a2',
        app: claudeApp('a2'),
      }),
      makeConnectedApp({
        consentId: 'c3',
        createdAt: '2026-07-05T00:00:00.000+00:00',
        appId: 'a3',
        app: makeApp({
          $id: 'a3',
          name: 'Cursor',
          logoUri: 'https://cursor.com/logo.png',
        }),
      }),
    ]

    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)

    // Groups ordered by most recent authorization.
    expect(groups[0].displayName).toBe('Claude Code (appwrite)')
    expect(groups[0].grants.map((g) => g.consent.$id)).toEqual(['c2', 'c1'])
    expect(groups[0].latestAuthorizedAt).toBe('2026-07-10T00:00:00.000+00:00')
    expect(groups[0].knownClient?.id).toBe('claude-code')

    expect(groups[1].displayName).toBe('Cursor')
    expect(groups[1].grants).toHaveLength(1)
  })

  it('keeps unresolved apps as their own rows keyed by app ID', () => {
    const grants = [
      makeConnectedApp({ consentId: 'c1', appId: 'x1', app: null }),
      makeConnectedApp({ consentId: 'c2', appId: 'x2', app: null }),
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)
    expect(groups.map((g) => g.displayName)).toEqual(['x1', 'x2'])
  })

  it('groups same-name apps even without a known-client match', () => {
    const grants = [
      makeConnectedApp({
        consentId: 'c1',
        appId: 'b1',
        app: makeApp({ $id: 'b1', name: 'My MCP Tool' }),
      }),
      makeConnectedApp({
        consentId: 'c2',
        appId: 'b2',
        app: makeApp({ $id: 'b2', name: 'my mcp tool ' }),
      }),
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(1)
    expect(groups[0].grants).toHaveLength(2)
  })

  it('keys CIMD consents by their URL and falls back to the host as display name', () => {
    const grants = [
      makeConnectedApp({
        consentId: 'c1',
        cimdUrl: 'https://tool.example.com/oauth/client.json',
        app: null,
      }),
      makeConnectedApp({
        consentId: 'c2',
        cimdUrl: 'https://other.example.com/client.json',
        app: makeApp({ $id: '', name: 'Other Tool' }),
      }),
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)
    expect(groups[0].key).toBe('cimd:https://tool.example.com/oauth/client.json')
    expect(groups[0].displayName).toBe('tool.example.com')
    expect(groups[1].displayName).toBe('Other Tool')
  })

  it('does not merge a CIMD client with a registered app of the same name', () => {
    const grants = [
      makeConnectedApp({
        consentId: 'c1',
        cimdUrl: 'https://tool.example.com/client.json',
        app: makeApp({ $id: '', name: 'My MCP Tool' }),
      }),
      makeConnectedApp({
        consentId: 'c2',
        appId: 'b1',
        app: makeApp({ $id: 'b1', name: 'My MCP Tool' }),
      }),
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)
  })

  it('keeps distinct CIMD URLs separate even when they match the same known client', () => {
    // Two different metadata documents on claude.ai both resolve to the
    // canonical "Claude" client, but they are distinct clients and must not
    // share a revoke-all row.
    const grants = [
      makeConnectedApp({
        consentId: 'c1',
        cimdUrl: 'https://claude.ai/mcp/one/client.json',
        app: makeApp({ $id: '', name: 'Claude' }),
      }),
      makeConnectedApp({
        consentId: 'c2',
        cimdUrl: 'https://claude.ai/mcp/two/client.json',
        app: makeApp({ $id: '', name: 'Claude' }),
      }),
    ]
    const groups = groupConnectedApps(grants)
    expect(groups).toHaveLength(2)
    // Known-client matching still drives display (icon + canonical name).
    expect(groups.every((g) => g.knownClient?.id === 'claude')).toBe(true)
    expect(groups.map((g) => g.key).sort()).toEqual([
      'cimd:https://claude.ai/mcp/one/client.json',
      'cimd:https://claude.ai/mcp/two/client.json',
    ])
  })
})

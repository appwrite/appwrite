import { describe, expect, it } from 'bun:test'
import type { Models } from '@appwrite.io/console'
import { APPWRITE_AGENT_OAUTH_CLIENT_ID } from '@/lib/assistant/mcp-appwrite'
import { hasAccountMcpAgentConnected } from '@/lib/mcp-adoption'

function consent(
  overrides: Partial<Models.Oauth2Consent>,
): Models.Oauth2Consent {
  return {
    $id: 'consent',
    $createdAt: '2026-09-01T00:00:00.000+00:00',
    $updatedAt: '2026-09-01T00:00:00.000+00:00',
    userId: 'user',
    appId: '',
    cimdUrl: '',
    scopes: [],
    resources: [],
    authorizationDetails: '',
    expire: '',
    ...overrides,
  }
}

describe('hasAccountMcpAgentConnected', () => {
  it('is false without consents', () => {
    expect(hasAccountMcpAgentConnected(undefined)).toBe(false)
    expect(hasAccountMcpAgentConnected(null)).toBe(false)
    expect(hasAccountMcpAgentConnected([])).toBe(false)
  })

  it('ignores the seeded console Agent client', () => {
    expect(
      hasAccountMcpAgentConnected([
        consent({ appId: APPWRITE_AGENT_OAUTH_CLIENT_ID }),
      ]),
    ).toBe(false)
  })

  it('counts registered third-party clients', () => {
    expect(
      hasAccountMcpAgentConnected([
        consent({ appId: APPWRITE_AGENT_OAUTH_CLIENT_ID }),
        consent({ $id: 'dcr', appId: '68f1e2a9b3c4d5e6f7a8' }),
      ]),
    ).toBe(true)
  })

  it('counts URL-form (CIMD) clients from the consent alone', () => {
    expect(
      hasAccountMcpAgentConnected([
        consent({
          cimdUrl: 'https://claude.ai/.well-known/oauth-client.json',
        }),
      ]),
    ).toBe(true)
  })
})

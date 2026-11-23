import { describe, expect, it } from 'bun:test'
import { cimdDocumentToApp, isCimdClientId } from '@/lib/oauth2/cimd'

describe('isCimdClientId', () => {
  it('accepts https URLs', () => {
    expect(
      isCimdClientId('https://example.com/oauth/client-metadata.json'),
    ).toBe(true)
  })

  it('accepts http only for loopback', () => {
    expect(isCimdClientId('http://localhost:3000/client.json')).toBe(true)
    expect(isCimdClientId('http://127.0.0.1/client.json')).toBe(true)
    expect(isCimdClientId('http://example.com/client.json')).toBe(false)
  })

  it('rejects plain app IDs and non-http schemes', () => {
    expect(isCimdClientId('my-app_1.0')).toBe(false)
    expect(isCimdClientId('64f1e2a9b3c4d5e6f7a8')).toBe(false)
    expect(isCimdClientId('javascript:alert(1)')).toBe(false)
  })
})

describe('cimdDocumentToApp', () => {
  const clientId = 'https://example.com/oauth/client-metadata.json'

  it('maps RFC 7591 metadata onto the App model', () => {
    const app = cimdDocumentToApp(clientId, {
      client_id: clientId,
      client_name: 'Example App',
      client_uri: 'https://example.com',
      logo_uri: 'https://example.com/logo.png',
      policy_uri: 'https://example.com/privacy',
      tos_uri: 'https://example.com/terms',
      contacts: ['support@example.com'],
      redirect_uris: ['https://example.com/callback'],
      token_endpoint_auth_method: 'none',
      grant_types: [
        'authorization_code',
        'urn:ietf:params:oauth:grant-type:device_code',
      ],
    })

    expect(app.$id).toBe(clientId)
    expect(app.name).toBe('Example App')
    expect(app.clientUri).toBe('https://example.com')
    expect(app.logoUri).toBe('https://example.com/logo.png')
    expect(app.privacyPolicyUrl).toBe('https://example.com/privacy')
    expect(app.termsUrl).toBe('https://example.com/terms')
    expect(app.contacts).toEqual(['support@example.com'])
    expect(app.redirectUris).toEqual(['https://example.com/callback'])
    expect(app.type).toBe('public')
    expect(app.deviceFlow).toBe(true)
    expect(app.enabled).toBe(true)
  })

  it('falls back to the hostname when client_name is missing', () => {
    const app = cimdDocumentToApp(clientId, { client_id: clientId })
    expect(app.name).toBe('example.com')
    expect(app.deviceFlow).toBe(false)
    expect(app.type).toBe('confidential')
  })

  it('rejects a document whose client_id does not match its URL', () => {
    expect(() =>
      cimdDocumentToApp(clientId, {
        client_id: 'https://evil.example/other.json',
      }),
    ).toThrow()
    expect(() => cimdDocumentToApp(clientId, 'not an object')).toThrow()
    expect(() => cimdDocumentToApp(clientId, null)).toThrow()
  })

  it('drops unrenderable URI values', () => {
    const app = cimdDocumentToApp(clientId, {
      client_id: clientId,
      logo_uri: 'javascript:alert(1)',
      client_uri: 'not a url',
      contacts: ['ok', 42],
    })
    expect(app.logoUri).toBe('')
    expect(app.clientUri).toBe('')
    expect(app.contacts).toEqual(['ok'])
  })
})

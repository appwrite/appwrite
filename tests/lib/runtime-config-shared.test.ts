import { describe, expect, it } from 'vitest'
import {
  isSelfHostedConsoleProfile,
  resolveAppwriteEndpointFallback,
  shouldWarnAboutMissingAppwriteEndpoint,
} from '@/lib/runtime-config-shared'

describe('isSelfHostedConsoleProfile', () => {
  it('recognizes normalized self-hosted profiles', () => {
    expect(isSelfHostedConsoleProfile('self-hosted')).toBe(true)
    expect(isSelfHostedConsoleProfile(' Self Hosted ')).toBe(true)
  })

  it('rejects cloud and missing profiles', () => {
    expect(isSelfHostedConsoleProfile('cloud')).toBe(false)
    expect(isSelfHostedConsoleProfile('')).toBe(false)
  })
})

describe('shouldWarnAboutMissingAppwriteEndpoint', () => {
  it('does not warn when self-hosted uses same-origin discovery', () => {
    expect(
      shouldWarnAboutMissingAppwriteEndpoint({
        appwriteEndpoint: '',
        consoleProfile: 'self-hosted',
      }),
    ).toBe(false)
  })

  it('warns when cloud has no configured endpoint', () => {
    expect(
      shouldWarnAboutMissingAppwriteEndpoint({
        appwriteEndpoint: '',
        consoleProfile: 'cloud',
      }),
    ).toBe(true)
  })

  it('does not warn when an endpoint is configured', () => {
    expect(
      shouldWarnAboutMissingAppwriteEndpoint({
        appwriteEndpoint: 'https://cloud.appwrite.io/v1',
        consoleProfile: 'cloud',
      }),
    ).toBe(false)
  })
})

describe('resolveAppwriteEndpointFallback', () => {
  it('uses the browser origin for self-hosted profiles', () => {
    expect(
      resolveAppwriteEndpointFallback('self-hosted', {
        protocol: 'https:',
        host: 'console.example.com:8443',
      }),
    ).toBe('https://console.example.com:8443/v1')
  })
})

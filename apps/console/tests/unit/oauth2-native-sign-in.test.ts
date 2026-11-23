/**
 * Native ID token sign-in is switched on separately from the browser flow.
 * These pin the pieces the drawer relies on: what reaches the server and the
 * rule that gates the switch.
 */

import { describe, expect, test } from 'bun:test'
import { ProjectOAuthProviderId } from '@appwrite.io/console'
import type { ProjectSdk } from '@/lib/appwrite/sdk'
import {
  getOAuth2NativeSignInError,
  isOAuth2ParameterRequiredWhenEnabling,
  NATIVE_CLIENT_IDS_PARAM_ID,
  supportsNativeSignIn,
} from '@/lib/oauth2/provider-field-requirements'
import {
  pruneOAuth2Body,
  updateProjectOAuth2Provider,
} from '@/lib/oauth2/update-project-oauth2'
import { getOAuth2SignInStatus } from '@/lib/oauth2/sign-in-status'

const CLIENT_ID = { $id: 'clientId', name: 'Client ID' }
const NATIVE = { $id: NATIVE_CLIENT_IDS_PARAM_ID, name: 'Native client IDs' }

function fakeProjectSdk() {
  const calls: unknown[] = []
  const sdk = {
    project: {
      updateOAuth2Google: async (body: unknown) => {
        calls.push(body)
      },
    },
  }
  return { sdk: sdk as unknown as ProjectSdk, calls }
}

describe('pruneOAuth2Body', () => {
  test('keeps both switches and trims credential strings', () => {
    expect(
      pruneOAuth2Body({
        enabled: false,
        nativeEnabled: true,
        clientId: '  abc  ',
        clientSecret: '',
      }),
    ).toEqual({ enabled: false, nativeEnabled: true, clientId: 'abc' })
  })

  test('keeps an empty list, since that is how a stored list is cleared', () => {
    expect(pruneOAuth2Body({ nativeClientIds: [] })).toEqual({
      nativeClientIds: [],
    })
    expect(pruneOAuth2Body({ nativeClientIds: [' a ', '', 'b'] })).toEqual({
      nativeClientIds: ['a', 'b'],
    })
  })
})

describe('updateProjectOAuth2Provider', () => {
  test('sends the pruned body through the typed SDK method', async () => {
    const { sdk, calls } = fakeProjectSdk()
    await updateProjectOAuth2Provider(sdk, ProjectOAuthProviderId.Google, {
      enabled: true,
      clientId: 'abc',
      clientSecret: '',
    })
    expect(calls).toEqual([{ enabled: true, clientId: 'abc' }])
  })

  test('native settings travel with the rest of the body', async () => {
    const { sdk, calls } = fakeProjectSdk()
    await updateProjectOAuth2Provider(sdk, ProjectOAuthProviderId.Google, {
      enabled: false,
      nativeEnabled: true,
      nativeClientIds: ['ios.apps.googleusercontent.com'],
    })
    expect(calls).toEqual([
      {
        enabled: false,
        nativeEnabled: true,
        nativeClientIds: ['ios.apps.googleusercontent.com'],
      },
    ])
  })
})

describe('native sign-in rules', () => {
  test('support comes from the server catalog, not a provider list', () => {
    expect(supportsNativeSignIn([CLIENT_ID, NATIVE])).toBe(true)
    expect(supportsNativeSignIn([CLIENT_ID])).toBe(false)
  })

  test('the audience list is never required by the browser flow', () => {
    expect(
      isOAuth2ParameterRequiredWhenEnabling(
        'apple',
        NATIVE_CLIENT_IDS_PARAM_ID,
        false,
      ),
    ).toBe(false)
  })

  test('switching native on needs a client ID or one native client ID', () => {
    const base = { parameters: [CLIENT_ID, NATIVE], nativeEnabled: true }
    expect(
      getOAuth2NativeSignInError({
        ...base,
        nativeClientIds: [],
        formFields: {},
      }),
    ).toBeDefined()
    expect(
      getOAuth2NativeSignInError({
        ...base,
        nativeClientIds: ['com.example.app'],
        formFields: {},
      }),
    ).toBeUndefined()
    expect(
      getOAuth2NativeSignInError({
        ...base,
        nativeClientIds: [],
        formFields: { clientId: 'web-client' },
      }),
    ).toBeUndefined()
    expect(
      getOAuth2NativeSignInError({
        ...base,
        nativeClientIds: [],
        formFields: {},
        initialFields: { clientId: 'stored-client' },
      }),
    ).toBeUndefined()
  })

  test('nothing is required while native sign-in stays off', () => {
    expect(
      getOAuth2NativeSignInError({
        parameters: [CLIENT_ID, NATIVE],
        nativeEnabled: false,
        nativeClientIds: [],
        formFields: {},
      }),
    ).toBeUndefined()
  })
})

describe('getOAuth2SignInStatus', () => {
  test('names the flows that are on, so native-only never reads as disabled', () => {
    const status = (browserEnabled: boolean, nativeEnabled: boolean) =>
      getOAuth2SignInStatus({ browserEnabled, nativeEnabled })

    expect(status(false, false)).toBe('off')
    expect(status(true, false)).toBe('browser')
    expect(status(false, true)).toBe('native')
    expect(status(true, true)).toBe('browser-and-native')
  })
})

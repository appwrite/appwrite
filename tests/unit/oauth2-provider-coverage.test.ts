/**
 * Every OAuth2 provider the console SDK knows about must be configurable from
 * the project auth settings grid. The grid hides providers that have no typed
 * `project.updateOAuth2*` handler, so a silent SDK bump that adds a provider
 * would otherwise drop it from the UI without any error.
 *
 * The one documented exception is the mock provider below, which the server
 * offers to its own end-to-end tests rather than to projects.
 */

import { describe, expect, test } from 'bun:test'
import { existsSync } from 'node:fs'
import { join } from 'node:path'
import { OAuthProvider, ProjectOAuthProviderId } from '@appwrite.io/console'
import {
  getOAuth2ProviderDisplayName,
  getOAuth2ProviderIconPath,
} from '@/lib/oauth2/provider-display'
import { canUpdateProjectOAuth2Provider } from '@/lib/oauth2/update-project-oauth2'

const PUBLIC_DIR = join(import.meta.dir, '..', '..', 'public')

/** Listed by the server catalog but without a project update endpoint in the SDK. */
const PROVIDERS_WITHOUT_UPDATE_ENDPOINT = new Set<string>([
  ProjectOAuthProviderId.Yammer,
])

/**
 * `OAuthProvider` covers everything the account API accepts, which includes the
 * provider Appwrite's own end-to-end tests sign in through. It has no project
 * settings, no `updateOAuth2*` method, no catalog entry and no brand icon, so
 * it is account-only and rightly absent from `ProjectOAuthProviderId`.
 */
const ACCOUNT_ONLY_PROVIDER_IDS = new Set<string>([OAuthProvider.Mocknoemail])

/** The providers a project can actually be configured to use. */
const CONFIGURABLE_PROVIDER_IDS = (
  Object.values(OAuthProvider) as string[]
).filter((id) => !ACCOUNT_ONLY_PROVIDER_IDS.has(id))

describe('OAuth2 provider coverage', () => {
  test('OAuthProvider and ProjectOAuthProviderId enums agree', () => {
    expect([...CONFIGURABLE_PROVIDER_IDS].sort()).toEqual(
      (Object.values(ProjectOAuthProviderId) as string[]).sort(),
    )
  })

  test('every provider with an update endpoint is configurable from the grid', () => {
    for (const providerId of Object.values(ProjectOAuthProviderId)) {
      const expected = !PROVIDERS_WITHOUT_UPDATE_ENDPOINT.has(providerId)
      expect(
        canUpdateProjectOAuth2Provider(providerId),
        `expected canUpdateProjectOAuth2Provider('${providerId}') to be ${expected}`,
      ).toBe(expected)
    }
  })

  test('includes the providers added in recent SDK releases', () => {
    for (const providerId of [
      OAuthProvider.Cloudflare,
      OAuthProvider.Kakao,
      OAuthProvider.Resend,
      OAuthProvider.Tiktok,
    ]) {
      expect(canUpdateProjectOAuth2Provider(providerId)).toBe(true)
    }
  })

  test('every provider card resolves to an existing icon asset', () => {
    for (const providerId of CONFIGURABLE_PROVIDER_IDS) {
      const iconPath = getOAuth2ProviderIconPath(providerId)
      expect(iconPath.startsWith('/icons/')).toBe(true)
      expect(
        existsSync(join(PUBLIC_DIR, iconPath)),
        `missing icon for '${providerId}' at public${iconPath}`,
      ).toBe(true)
    }
  })

  test('uses brand casing for display names', () => {
    expect(getOAuth2ProviderDisplayName(OAuthProvider.Tiktok)).toBe('TikTok')
    expect(getOAuth2ProviderDisplayName(OAuthProvider.Kakao)).toBe('Kakao')
    expect(getOAuth2ProviderDisplayName(OAuthProvider.Linkedin)).toBe(
      'LinkedIn',
    )
    expect(getOAuth2ProviderDisplayName(OAuthProvider.Wordpress)).toBe(
      'WordPress',
    )
    expect(getOAuth2ProviderDisplayName(OAuthProvider.PaypalSandbox)).toBe(
      'PayPal Sandbox',
    )
  })
})

import { sdk } from '@/lib/appwrite/sdk'
import { getNavigableRedirect } from '@/lib/oauth2/redirect'
import type { MarketplaceApp } from './types'

export async function startMarketplaceAppInstall(app: MarketplaceApp) {
  const redirectUri = app.redirectUris?.[0]?.trim()
  if (!redirectUri) {
    throw new Error(
      'This app has no redirect URI configured. Ask the publisher to add one before installing.',
    )
  }

  const result = await sdk.forConsole.oauth2.authorize({
    clientId: app.$id,
    redirectUri,
    responseType: 'code',
    scope: 'openid profile email',
    prompt: 'consent',
  })

  const redirectUrl = getNavigableRedirect(result.redirectUrl)
  if (redirectUrl) {
    window.location.assign(redirectUrl)
    return
  }

  if (result.grantId) {
    window.location.assign(`/oauth2/consent?grant_id=${encodeURIComponent(result.grantId)}`)
    return
  }

  throw new Error('Could not start authorization for this app.')
}

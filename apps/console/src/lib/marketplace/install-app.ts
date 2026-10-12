import { sdk } from '@/lib/appwrite/sdk'
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

  if (result.redirectUrl) {
    window.location.assign(result.redirectUrl)
    return
  }

  if (result.grantId) {
    window.location.assign(`/oauth2/consent?grant_id=${encodeURIComponent(result.grantId)}`)
    return
  }

  throw new Error('Could not start authorization for this app.')
}

import { OAuthProvider } from '@appwrite.io/console'

/** Shown first in the OAuth2 grid (subset of {@link OAuthProvider}). */
export const OAUTH2_POPULAR_PROVIDER_IDS: ReadonlySet<string> = new Set([
  OAuthProvider.Apple,
  OAuthProvider.Appwrite,
  OAuthProvider.Facebook,
  OAuthProvider.Github,
  OAuthProvider.Google,
  OAuthProvider.Microsoft,
])

const DISPLAY_NAME_OVERRIDES: Record<string, string> = {
  [OAuthProvider.Github]: 'GitHub',
  [OAuthProvider.Gitlab]: 'GitLab',
  [OAuthProvider.Linkedin]: 'LinkedIn',
  [OAuthProvider.Paypal]: 'PayPal',
  [OAuthProvider.PaypalSandbox]: 'PayPal Sandbox',
  [OAuthProvider.Tiktok]: 'TikTok',
  [OAuthProvider.TradeshiftBox]: 'Tradeshift Sandbox',
  [OAuthProvider.Oidc]: 'OpenID Connect',
  [OAuthProvider.Fusionauth]: 'FusionAuth',
  [OAuthProvider.Huggingface]: 'Hugging Face',
  [OAuthProvider.Keycloak]: 'Keycloak',
  [OAuthProvider.Wordpress]: 'WordPress',
}

export function getOAuth2ProviderDisplayName(providerId: string): string {
  if (DISPLAY_NAME_OVERRIDES[providerId]) {
    return DISPLAY_NAME_OVERRIDES[providerId]!
  }
  const spaced = providerId.replace(/([a-z])([A-Z])/g, '$1 $2')
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * Provider ids whose icon filename differs from `${providerId.toLowerCase()}.svg`.
 * Every other {@link OAuthProvider} has a dedicated `/public/icons/<id>.svg`.
 */
const ICON_FILENAME_OVERRIDES: Record<string, string> = {
  [OAuthProvider.Discord]: 'discord-simple.svg',
  [OAuthProvider.Huggingface]: 'hugging-face.svg',
  [OAuthProvider.PaypalSandbox]: 'paypal.svg',
  [OAuthProvider.TradeshiftBox]: 'tradeshift.svg',
}

/** Map provider id to the matching `/public/icons/*.svg` asset. */
export function getOAuth2ProviderIconPath(providerId: string): string {
  const file =
    ICON_FILENAME_OVERRIDES[providerId] ?? `${providerId.toLowerCase()}.svg`
  return `/icons/${file}`
}

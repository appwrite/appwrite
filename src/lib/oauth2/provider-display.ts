import { OAuthProvider } from '@appwrite.io/console'

/** Shown first in the OAuth2 grid (subset of {@link OAuthProvider}). */
export const OAUTH2_POPULAR_PROVIDER_IDS: ReadonlySet<string> = new Set([
  OAuthProvider.Apple,
  OAuthProvider.Facebook,
  OAuthProvider.Github,
  OAuthProvider.Google,
  OAuthProvider.Linkedin,
  OAuthProvider.Microsoft,
])

const DISPLAY_NAME_OVERRIDES: Record<string, string> = {
  [OAuthProvider.Github]: 'GitHub',
  [OAuthProvider.Gitlab]: 'GitLab',
  [OAuthProvider.Paypal]: 'PayPal',
  [OAuthProvider.PaypalSandbox]: 'PayPal Sandbox',
  [OAuthProvider.TradeshiftBox]: 'Tradeshift Sandbox',
  [OAuthProvider.Oidc]: 'OpenID Connect',
  [OAuthProvider.Fusionauth]: 'FusionAuth',
  [OAuthProvider.Keycloak]: 'Keycloak',
}

export function getOAuth2ProviderDisplayName(providerId: string): string {
  if (DISPLAY_NAME_OVERRIDES[providerId]) {
    return DISPLAY_NAME_OVERRIDES[providerId]!
  }
  const spaced = providerId.replace(/([a-z])([A-Z])/g, '$1 $2')
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/** Map provider id to existing `/public/icons/*.svg` asset; fall back when no dedicated asset exists. */
export function getOAuth2ProviderIconPath(providerId: string): string {
  const map: Record<string, string> = {
    [OAuthProvider.Discord]: 'discord-simple.svg',
    [OAuthProvider.Fusionauth]: 'auth0.svg',
    [OAuthProvider.Keycloak]: 'auth0.svg',
    [OAuthProvider.Kick]: 'twitch.svg',
    [OAuthProvider.X]: 'x.svg',
    [OAuthProvider.Wordpress]: 'wordpress.svg',
  }
  const file = map[providerId] ?? `${providerId.toLowerCase()}.svg`
  return `/icons/${file}`
}

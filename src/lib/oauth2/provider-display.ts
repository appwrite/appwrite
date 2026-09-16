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

/** How long a newly added OAuth2 provider stays marked as new. */
export const OAUTH2_NEW_BADGE_DAYS = 45

/**
 * UTC launch dates (YYYY-MM-DD) for providers that should show a temporary
 * "New" badge in the Auth OAuth2 settings grid.
 */
export const OAUTH2_NEW_PROVIDER_LAUNCH_DATES: Partial<Record<string, string>> =
  {
    [OAuthProvider.Cloudflare]: '2026-09-08',
    [OAuthProvider.Huggingface]: '2026-08-26',
    [OAuthProvider.Kakao]: '2026-09-08',
    [OAuthProvider.Resend]: '2026-09-08',
    [OAuthProvider.Tiktok]: '2026-09-08',
  }

function parseUtcDate(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(Date.UTC(year, (month ?? 1) - 1, day ?? 1))
}

function addUtcDays(date: Date, days: number): Date {
  const result = new Date(date.getTime())
  result.setUTCDate(result.getUTCDate() + days)
  return result
}

export function isOAuth2ProviderNew(
  providerId: string,
  now: Date = new Date(),
): boolean {
  const launchedAt = OAUTH2_NEW_PROVIDER_LAUNCH_DATES[providerId]
  if (!launchedAt) return false

  const launch = parseUtcDate(launchedAt)
  if (Number.isNaN(launch.getTime())) return false

  return now.getTime() < addUtcDays(launch, OAUTH2_NEW_BADGE_DAYS).getTime()
}

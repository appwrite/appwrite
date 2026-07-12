import type { Project } from '@appwrite.io/console'
import { ProjectOAuthProviderId } from '@appwrite.io/console'
import type { ProjectSdk } from '@/lib/appwrite/sdk'

/** Providers listed in the catalog enum but without a project update endpoint in this SDK. */
const OAUTH2_PROVIDER_IDS_WITHOUT_UPDATE = new Set<string>([
  ProjectOAuthProviderId.Yammer,
])

type OAuth2UpdateBody = Record<string, unknown>

type OAuth2UpdateHandler = (
  project: Project,
  body: OAuth2UpdateBody,
) => Promise<unknown>

/**
 * Maps each {@link ProjectOAuthProviderId} to the matching `project.updateOAuth2*` SDK method.
 */
const OAUTH2_UPDATE_BY_PROVIDER: Partial<
  Record<ProjectOAuthProviderId, OAuth2UpdateHandler>
> = {
  [ProjectOAuthProviderId.Amazon]: (p, b) => p.updateOAuth2Amazon(b),
  [ProjectOAuthProviderId.Apple]: (p, b) => p.updateOAuth2Apple(b),
  [ProjectOAuthProviderId.Appwrite]: (p, b) => p.updateOAuth2Appwrite(b),
  [ProjectOAuthProviderId.Auth0]: (p, b) => p.updateOAuth2Auth0(b),
  [ProjectOAuthProviderId.Authentik]: (p, b) => p.updateOAuth2Authentik(b),
  [ProjectOAuthProviderId.Autodesk]: (p, b) => p.updateOAuth2Autodesk(b),
  [ProjectOAuthProviderId.Bitbucket]: (p, b) => p.updateOAuth2Bitbucket(b),
  [ProjectOAuthProviderId.Bitly]: (p, b) => p.updateOAuth2Bitly(b),
  [ProjectOAuthProviderId.Box]: (p, b) => p.updateOAuth2Box(b),
  [ProjectOAuthProviderId.Dailymotion]: (p, b) => p.updateOAuth2Dailymotion(b),
  [ProjectOAuthProviderId.Discord]: (p, b) => p.updateOAuth2Discord(b),
  [ProjectOAuthProviderId.Disqus]: (p, b) => p.updateOAuth2Disqus(b),
  [ProjectOAuthProviderId.Dropbox]: (p, b) => p.updateOAuth2Dropbox(b),
  [ProjectOAuthProviderId.Etsy]: (p, b) => p.updateOAuth2Etsy(b),
  [ProjectOAuthProviderId.Facebook]: (p, b) => p.updateOAuth2Facebook(b),
  [ProjectOAuthProviderId.Figma]: (p, b) => p.updateOAuth2Figma(b),
  [ProjectOAuthProviderId.Fusionauth]: (p, b) => p.updateOAuth2FusionAuth(b),
  [ProjectOAuthProviderId.Github]: (p, b) => p.updateOAuth2GitHub(b),
  [ProjectOAuthProviderId.Gitlab]: (p, b) => p.updateOAuth2Gitlab(b),
  [ProjectOAuthProviderId.Google]: (p, b) => p.updateOAuth2Google(b),
  [ProjectOAuthProviderId.Keycloak]: (p, b) => p.updateOAuth2Keycloak(b),
  [ProjectOAuthProviderId.Kick]: (p, b) => p.updateOAuth2Kick(b),
  [ProjectOAuthProviderId.Linkedin]: (p, b) => p.updateOAuth2Linkedin(b),
  [ProjectOAuthProviderId.Microsoft]: (p, b) => p.updateOAuth2Microsoft(b),
  [ProjectOAuthProviderId.Notion]: (p, b) => p.updateOAuth2Notion(b),
  [ProjectOAuthProviderId.Oidc]: (p, b) => p.updateOAuth2Oidc(b),
  [ProjectOAuthProviderId.Okta]: (p, b) => p.updateOAuth2Okta(b),
  [ProjectOAuthProviderId.Paypal]: (p, b) => p.updateOAuth2Paypal(b),
  [ProjectOAuthProviderId.PaypalSandbox]: (p, b) => p.updateOAuth2PaypalSandbox(b),
  [ProjectOAuthProviderId.Podio]: (p, b) => p.updateOAuth2Podio(b),
  [ProjectOAuthProviderId.Salesforce]: (p, b) => p.updateOAuth2Salesforce(b),
  [ProjectOAuthProviderId.Slack]: (p, b) => p.updateOAuth2Slack(b),
  [ProjectOAuthProviderId.Spotify]: (p, b) => p.updateOAuth2Spotify(b),
  [ProjectOAuthProviderId.Stripe]: (p, b) => p.updateOAuth2Stripe(b),
  [ProjectOAuthProviderId.Tradeshift]: (p, b) => p.updateOAuth2Tradeshift(b),
  [ProjectOAuthProviderId.TradeshiftBox]: (p, b) =>
    p.updateOAuth2TradeshiftSandbox(b),
  [ProjectOAuthProviderId.Twitch]: (p, b) => p.updateOAuth2Twitch(b),
  [ProjectOAuthProviderId.Wordpress]: (p, b) => p.updateOAuth2WordPress(b),
  [ProjectOAuthProviderId.X]: (p, b) => p.updateOAuth2X(b),
  [ProjectOAuthProviderId.Yahoo]: (p, b) => p.updateOAuth2Yahoo(b),
  [ProjectOAuthProviderId.Yandex]: (p, b) => p.updateOAuth2Yandex(b),
  [ProjectOAuthProviderId.Zoho]: (p, b) => p.updateOAuth2Zoho(b),
  [ProjectOAuthProviderId.Zoom]: (p, b) => p.updateOAuth2Zoom(b),
}

export function isProjectOAuthProviderId(
  providerId: string,
): providerId is ProjectOAuthProviderId {
  return (Object.values(ProjectOAuthProviderId) as string[]).includes(
    providerId,
  )
}

export function canUpdateProjectOAuth2Provider(providerId: string): boolean {
  if (!isProjectOAuthProviderId(providerId)) return false
  if (OAUTH2_PROVIDER_IDS_WITHOUT_UPDATE.has(providerId)) return false
  return OAUTH2_UPDATE_BY_PROVIDER[providerId] != null
}

/** Drop empty strings so omitted credentials are left unchanged on the server. */
function pruneOAuth2Body(
  values: Record<string, string | boolean>,
): OAuth2UpdateBody {
  const body: OAuth2UpdateBody = {}
  if (typeof values.enabled === 'boolean') {
    body.enabled = values.enabled
  }
  for (const [key, value] of Object.entries(values)) {
    if (key === 'enabled') continue
    if (typeof value === 'string') {
      const trimmed = value.trim()
      if (trimmed !== '') body[key] = trimmed
    }
  }
  return body
}

/**
 * Updates one OAuth2 provider using the typed project SDK method for that provider.
 */
export async function updateProjectOAuth2Provider(
  projectSdk: ProjectSdk,
  providerId: string,
  values: Record<string, string | boolean>,
): Promise<void> {
  if (!isProjectOAuthProviderId(providerId)) {
    throw new Error(`Unknown OAuth2 provider "${providerId}".`)
  }

  if (OAUTH2_PROVIDER_IDS_WITHOUT_UPDATE.has(providerId)) {
    throw new Error(
      `OAuth2 provider "${providerId}" is not supported by this Appwrite server version.`,
    )
  }

  const handler = OAUTH2_UPDATE_BY_PROVIDER[providerId]
  if (!handler) {
    throw new Error(`No OAuth2 update method for provider "${providerId}".`)
  }

  await handler(projectSdk.project, pruneOAuth2Body(values))
}

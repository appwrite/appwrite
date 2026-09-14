import type { Project } from '@appwrite.io/console'
import { ProjectOAuthProviderId } from '@appwrite.io/console'
import type { ProjectSdk } from '@/lib/appwrite/sdk'

/** Providers listed in the catalog enum but without a project update endpoint in this SDK. */
const OAUTH2_PROVIDER_IDS_WITHOUT_UPDATE = new Set<string>([
  ProjectOAuthProviderId.Yammer,
])

type OAuth2UpdateBody = Record<string, unknown>

/** Form values for one provider: credential strings, the two switches, and the native audience list. */
export type OAuth2UpdateValues = Record<string, string | boolean | string[]>

/** Settings the pinned SDK's typed `updateOAuth2*` methods do not know about yet. */
const NATIVE_SIGN_IN_KEYS = new Set(['nativeEnabled', 'nativeClientIds'])

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
  [ProjectOAuthProviderId.Cloudflare]: (p, b) => p.updateOAuth2Cloudflare(b),
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
  [ProjectOAuthProviderId.Huggingface]: (p, b) => p.updateOAuth2HuggingFace(b),
  [ProjectOAuthProviderId.Kakao]: (p, b) => p.updateOAuth2Kakao(b),
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
  [ProjectOAuthProviderId.Resend]: (p, b) => p.updateOAuth2Resend(b),
  [ProjectOAuthProviderId.Salesforce]: (p, b) => p.updateOAuth2Salesforce(b),
  [ProjectOAuthProviderId.Slack]: (p, b) => p.updateOAuth2Slack(b),
  [ProjectOAuthProviderId.Spotify]: (p, b) => p.updateOAuth2Spotify(b),
  [ProjectOAuthProviderId.Stripe]: (p, b) => p.updateOAuth2Stripe(b),
  [ProjectOAuthProviderId.Tiktok]: (p, b) => p.updateOAuth2TikTok(b),
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

/**
 * Drop empty strings so omitted credentials are left unchanged on the server.
 * Switches always travel, and so do lists: an empty list is how a stored list
 * gets cleared.
 */
export function pruneOAuth2Body(values: OAuth2UpdateValues): OAuth2UpdateBody {
  const body: OAuth2UpdateBody = {}
  for (const [key, value] of Object.entries(values)) {
    if (typeof value === 'boolean') {
      body[key] = value
    } else if (Array.isArray(value)) {
      body[key] = value.map((item) => item.trim()).filter((item) => item !== '')
    } else {
      const trimmed = value.trim()
      if (trimmed !== '') body[key] = trimmed
    }
  }
  return body
}

/**
 * The pinned console SDK predates native ID token sign-in. Its typed
 * `updateOAuth2*` methods forward only the parameters they know about, so
 * `nativeEnabled` and `nativeClientIds` would be dropped before the request
 * leaves the browser. Those updates go through the SDK client directly; the
 * typed method takes over again once the SDK is regenerated from the new spec.
 */
async function updateThroughClient(
  projectSdk: ProjectSdk,
  providerId: string,
  body: OAuth2UpdateBody,
): Promise<void> {
  const { client } = projectSdk
  const uri = new URL(
    `${client.config.endpoint}/project/oauth2/${encodeURIComponent(providerId)}`,
  )
  await client.call(
    'patch',
    uri,
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    body,
  )
}

/**
 * Updates one OAuth2 provider using the typed project SDK method for that provider.
 */
export async function updateProjectOAuth2Provider(
  projectSdk: ProjectSdk,
  providerId: string,
  values: OAuth2UpdateValues,
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

  const body = pruneOAuth2Body(values)
  if (Object.keys(body).some((key) => NATIVE_SIGN_IN_KEYS.has(key))) {
    await updateThroughClient(projectSdk, providerId, body)
    return
  }

  await handler(projectSdk.project, body)
}

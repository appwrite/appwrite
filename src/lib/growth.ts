/**
 * Conversations with the Appwrite team (support, feedback, docs ratings, and
 * sales applications) through Appwrite Cloud's `POST /v1/growth/conversations`.
 *
 * Cloud consoles call their own API endpoint and identify the signed-in user
 * with a short-lived JWT, so the server takes the email, name and user ID from
 * the account and derives the billing plan from the organization. The route
 * accepts any origin, so Cloud never sends the session cookie to it
 * (Access-Control-Allow-Credentials is false); the JWT carries the identity
 * instead. Self-hosted servers have no growth route, so self-hosted consoles
 * send anonymously to Appwrite Cloud, where the email param is required.
 *
 * Requests go through the console SDK's Growth service on a dedicated client
 * that never sends cookies; the attachment (5 MB at most) goes out in the same
 * multipart request.
 */

import {
  AppwriteException,
  Client,
  ConversationType,
  Growth,
} from '@appwrite.io/console'
import { getBaseEndpoint, sdk } from '@/lib/appwrite/sdk'
import { isCloudProfile } from '@/lib/console-profiles'
import { DEFAULT_CLOUD_APPWRITE_ENDPOINT } from '@/lib/runtime-config-shared'

export { ConversationType }

type UtmAttributes = {
  utmSource?: string
  utmMedium?: string
  utmReferral?: string
}

type ApplicationAttributes = UtmAttributes & {
  companyName: string
  companyUrl: string
}

/** Attribute keys the server accepts per type; any other key is rejected with 400. */
export type ConversationAttributes = {
  [ConversationType.Support]: {
    employees?: string
    country?: string
    role?: string
    website?: string
  }
  [ConversationType.Feedback]: {
    route?: string
    npsScore?: number
    source?: string
  }
  [ConversationType.Docs]: {
    rating: 'positive' | 'negative'
    route?: string
  }
  [ConversationType.Enterprise]: UtmAttributes & {
    companyName: string
    companySize: string
    companyWebsite: string
    preferredDeployment?: string
    timeline?: string
    cloudEmail?: string
    platform?: 'appwrite' | 'imagine'
  }
  [ConversationType.Startup]: ApplicationAttributes
  [ConversationType.Partner]: ApplicationAttributes
}

type ConversationFields<Type extends keyof ConversationAttributes> = {
  type: Type
  /** Required without a console session; ignored by the server with one. */
  email?: string
  name?: string
  subject?: string
  message?: string
  organizationId?: string
  projectId?: string
  attributes?: ConversationAttributes[Type]
  /** Support and feedback only, 5 MB at most. */
  attachment?: File
  /**
   * Identify the signed-in user with a JWT so the server uses their account.
   * Pass false when the form collects contact details that must be kept.
   */
  session?: boolean
}

export type CreateConversationParams = {
  [Type in keyof ConversationAttributes]: ConversationFields<Type>
}[keyof ConversationAttributes]

type GrowthTarget = {
  endpoint: string
  identify: boolean
}

const RATE_LIMIT_MESSAGE = 'Too many requests. Try again in a few minutes.'
const SERVER_ERROR_MESSAGE = 'Internal server error.'
const FALLBACK_ERROR_MESSAGE = 'Error submitting form. Please contact support.'

export class GrowthError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'GrowthError'
    this.status = status
  }

  get isRateLimited(): boolean {
    return this.status === 429
  }
}

/**
 * Where conversations go: the console's own API, identified by the user's JWT,
 * on Cloud; Appwrite Cloud anonymously everywhere else.
 */
function resolveGrowthTarget(
  cloud: boolean,
  endpoint: string,
  session: boolean,
): GrowthTarget {
  if (!cloud) {
    return { endpoint: DEFAULT_CLOUD_APPWRITE_ENDPOINT, identify: false }
  }
  return { endpoint: endpoint.replace(/\/+$/, ''), identify: session }
}

/**
 * A JWT for the signed-in console user, or null when there is no session, so
 * signed-out visitors send anonymously with the email they typed. Any other
 * failure is thrown: sending anonymously would drop a signed-in user's
 * identity and organization.
 *
 * @throws GrowthError when the JWT cannot be created for a signed-in user.
 */
async function createSessionJwt(): Promise<string | null> {
  try {
    const { jwt } = await sdk.forConsole.account.createJWT()
    return jwt
  } catch (error) {
    if (error instanceof AppwriteException && error.code === 401) {
      return null
    }
    const status = error instanceof AppwriteException ? error.code : 0
    throw new GrowthError(
      status === 429 ? RATE_LIMIT_MESSAGE : FALLBACK_ERROR_MESSAGE,
      status,
    )
  }
}

/** Drops empty values: the server treats them as absent, and some validators reject ''. */
function compact<Value>(
  record: Record<string, Value | undefined | null>,
): Record<string, Value> {
  const entries = Object.entries(record).filter(
    (entry): entry is [string, Value] =>
      entry[1] !== undefined &&
      entry[1] !== null &&
      (typeof entry[1] !== 'string' || entry[1].trim() !== ''),
  )
  return Object.fromEntries(entries)
}

function toGrowthError(error: unknown): GrowthError {
  const status = error instanceof AppwriteException ? error.code : 0
  if (status === 429) {
    return new GrowthError(RATE_LIMIT_MESSAGE, status)
  }
  if (status >= 500) {
    return new GrowthError(SERVER_ERROR_MESSAGE, status)
  }
  const message = error instanceof AppwriteException ? error.message.trim() : ''
  return new GrowthError(message || FALLBACK_ERROR_MESSAGE, status)
}

/**
 * Creates a conversation with the Appwrite team.
 *
 * @throws GrowthError when the server rejects the request (400, 429, 5xx).
 */
export async function createConversation(
  params: CreateConversationParams,
): Promise<void> {
  const { endpoint, identify } = resolveGrowthTarget(
    isCloudProfile(),
    getBaseEndpoint(),
    params.session ?? true,
  )
  const jwt = identify ? await createSessionJwt() : null

  const client = new Client()
    .setEndpoint(endpoint)
    .setProject('console')
    .setCredentials('omit')
  if (jwt) {
    client.setJWT(jwt)
  }

  const fields = compact<string>({
    email: params.email?.trim(),
    name: params.name?.trim(),
    subject: params.subject?.trim(),
    message: params.message?.trim(),
    organizationId: params.organizationId,
    projectId: params.projectId,
  })
  const attributes = compact<string | number>(params.attributes ?? {})

  try {
    await new Growth(client).createConversation({
      type: params.type,
      ...fields,
      ...(Object.keys(attributes).length > 0 ? { attributes } : {}),
      ...(params.attachment ? { attachment: params.attachment } : {}),
    })
  } catch (error) {
    throw toGrowthError(error)
  }
}

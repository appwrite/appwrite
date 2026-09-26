/**
 * Conversations with the Appwrite team (support, feedback, docs ratings, and
 * sales applications) through Appwrite Cloud's `POST /v1/growth/conversations`.
 *
 * Cloud consoles call their own API endpoint with the console session, so the
 * server takes the email, name and user ID from the session and derives the
 * billing plan from the organization. Self-hosted servers have no growth
 * route, so self-hosted consoles send anonymously to Appwrite Cloud, where the
 * email param is required.
 *
 * The console SDK has no growth service, and the attachment has to go out in
 * one multipart request rather than through the SDK's chunked upload, so this
 * module uses fetch.
 */

import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { isCloudProfile } from '@/lib/console-profiles'
import { DEFAULT_CLOUD_APPWRITE_ENDPOINT } from '@/lib/runtime-config-shared'

export const ConversationType = {
  Support: 'support',
  Feedback: 'feedback',
  Docs: 'docs',
  Enterprise: 'enterprise',
  Startup: 'startup',
  Partner: 'partner',
} as const

export type ConversationType =
  (typeof ConversationType)[keyof typeof ConversationType]

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
  support: {
    employees?: string
    country?: string
    role?: string
    website?: string
  }
  feedback: {
    route?: string
    npsScore?: number
    source?: string
  }
  docs: {
    rating: 'positive' | 'negative'
    route?: string
  }
  enterprise: UtmAttributes & {
    companyName: string
    companySize: string
    companyWebsite: string
    preferredDeployment?: string
    timeline?: string
    cloudEmail?: string
    platform?: 'appwrite' | 'imagine'
  }
  startup: ApplicationAttributes
  partner: ApplicationAttributes
}

type ConversationFields<Type extends ConversationType> = {
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
   * Send the console session so the server identifies the signed-in user.
   * Pass false when the form collects contact details that must be kept.
   */
  session?: boolean
}

export type CreateConversationParams = {
  [Type in ConversationType]: ConversationFields<Type>
}[ConversationType]

export type Conversation = {
  type: ConversationType
  email: string
  organizationId: string
}

export type GrowthTarget = {
  endpoint: string
  credentials: RequestCredentials
}

export const CONVERSATIONS_PATH = '/growth/conversations'

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
 * Where conversations go: the console's own API with the session on Cloud,
 * Appwrite Cloud without credentials everywhere else.
 */
export function resolveGrowthTarget(
  cloud: boolean,
  endpoint: string,
  session: boolean,
): GrowthTarget {
  if (!cloud) {
    return { endpoint: DEFAULT_CLOUD_APPWRITE_ENDPOINT, credentials: 'omit' }
  }
  return {
    endpoint: endpoint.replace(/\/+$/, ''),
    credentials: session ? 'include' : 'omit',
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

function buildBody(params: CreateConversationParams): {
  body: BodyInit
  json: boolean
} {
  const fields = compact<string>({
    type: params.type,
    email: params.email?.trim(),
    name: params.name?.trim(),
    subject: params.subject?.trim(),
    message: params.message?.trim(),
    organizationId: params.organizationId,
    projectId: params.projectId,
  })
  const attributes = compact<string | number>(params.attributes ?? {})
  const hasAttributes = Object.keys(attributes).length > 0

  if (!params.attachment) {
    return {
      body: JSON.stringify(hasAttributes ? { ...fields, attributes } : fields),
      json: true,
    }
  }

  const form = new FormData()
  for (const [key, value] of Object.entries(fields)) {
    form.append(key, value)
  }
  if (hasAttributes) {
    form.append('attributes', JSON.stringify(attributes))
  }
  form.append('attachment', params.attachment)
  return { body: form, json: false }
}

async function toError(response: Response): Promise<GrowthError> {
  if (response.status === 429) {
    return new GrowthError(RATE_LIMIT_MESSAGE, response.status)
  }
  if (response.status >= 500) {
    return new GrowthError(SERVER_ERROR_MESSAGE, response.status)
  }

  let message = ''
  try {
    const data = (await response.json()) as { message?: unknown }
    if (typeof data.message === 'string') {
      message = data.message.trim()
    }
  } catch {
    // Non-JSON error body
  }
  return new GrowthError(message || FALLBACK_ERROR_MESSAGE, response.status)
}

/**
 * Creates a conversation with the Appwrite team.
 *
 * @throws GrowthError when the server rejects the request (400, 429, 5xx).
 */
export async function createConversation(
  params: CreateConversationParams,
): Promise<Conversation> {
  const { endpoint, credentials } = resolveGrowthTarget(
    isCloudProfile(),
    getBaseEndpoint(),
    params.session ?? true,
  )
  const { body, json } = buildBody(params)

  const headers: Record<string, string> = { 'X-Appwrite-Project': 'console' }
  if (json) {
    // Multipart bodies get their boundary from fetch.
    headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(`${endpoint}${CONVERSATIONS_PATH}`, {
    method: 'POST',
    headers,
    credentials,
    body,
  })

  if (response.status !== 201) {
    throw await toError(response)
  }

  return (await response.json()) as Conversation
}

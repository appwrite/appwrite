import { ConversationType, createConversation } from '@/lib/growth'
import { getReferrerAndUtmSource } from '@/lib/marketing/utm'

/** Prefixes `https://` when the visitor typed a bare domain. */
function withProtocol(url: string): string {
  const trimmed = url.trim()
  return trimmed.startsWith('http') ? trimmed : `https://${trimmed}`
}

export type PartnerApplicationPayload = {
  name: string
  email: string
  companyName: string
  companyUrl: string
  message: string
}

/**
 * Sent without the console session: the applicant's typed contact details
 * must be kept, and the server would replace them with the session's.
 *
 * @throws GrowthError when the server rejects the request.
 */
export async function submitPartnerApplication(
  payload: PartnerApplicationPayload,
): Promise<void> {
  await createConversation({
    type: ConversationType.Partner,
    session: false,
    name: payload.name,
    email: payload.email,
    message: payload.message,
    attributes: {
      companyName: payload.companyName,
      companyUrl: withProtocol(payload.companyUrl),
      ...getReferrerAndUtmSource(),
    },
  })
}

export type StartupsApplicationPayload = {
  name: string
  email: string
  companyName: string
  companyUrl: string
}

/**
 * Sent without the console session, like partner applications.
 *
 * @throws GrowthError when the server rejects the request.
 */
export async function submitStartupsApplication(
  payload: StartupsApplicationPayload,
): Promise<void> {
  await createConversation({
    type: ConversationType.Startup,
    session: false,
    name: payload.name,
    email: payload.email,
    attributes: {
      companyName: payload.companyName,
      companyUrl: withProtocol(payload.companyUrl),
      ...getReferrerAndUtmSource(),
    },
  })
}

export type EnterpriseApplicationPayload = {
  firstName: string
  lastName: string
  email: string
  companyName: string
  companySize: string
  companyWebsite: string
  preferredDeployment?: string | null
  timeline?: string | null
  useCase: string
  cloudEmail?: string | null
  /**
   * Identify the signed-in console user from the session. Leave off when the
   * form lets the visitor type the contact email.
   */
  session?: boolean
}

/**
 * @throws GrowthError when the server rejects the request.
 */
export async function submitEnterpriseApplication(
  payload: EnterpriseApplicationPayload,
): Promise<void> {
  await createConversation({
    type: ConversationType.Enterprise,
    session: payload.session ?? false,
    name: `${payload.firstName.trim()} ${payload.lastName.trim()}`,
    email: payload.email,
    message: payload.useCase,
    attributes: {
      companyName: payload.companyName,
      companySize: payload.companySize,
      companyWebsite: withProtocol(payload.companyWebsite),
      preferredDeployment: payload.preferredDeployment ?? undefined,
      timeline: payload.timeline ?? undefined,
      cloudEmail: payload.cloudEmail ?? undefined,
      platform: 'appwrite',
      ...getReferrerAndUtmSource(),
    },
  })
}

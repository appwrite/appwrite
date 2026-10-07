import { submitEnterpriseApplication } from '@/lib/marketing/growth-forms'

export type CustomerStoryInterviewPayload = {
  firstName: string
  lastName: string
  email: string
  companyName: string
  companyWebsite: string
  storySummary: string
  cloudEmail?: string
}

/** Enterprise conversations require these; not collected in the feedback popover. */
const CONSOLE_CUSTOMER_STORY_ENTERPRISE_DEFAULTS = {
  companySize: 'Not specified (console customer story)',
  preferredDeployment: 'Appwrite Cloud',
  timeline: 'Just researching',
} as const

/**
 * Files the request as an enterprise conversation (same as the `/sales`
 * wizard), identified by the console session.
 *
 * @throws GrowthError when the server rejects the request.
 */
export async function submitCustomerStoryInterviewRequest(
  payload: CustomerStoryInterviewPayload,
): Promise<void> {
  const useCase = [
    'Customer story interview request (console feedback)',
    '',
    payload.storySummary.trim(),
  ].join('\n')

  const firstName = payload.firstName.trim() || 'Unknown'
  const lastName = payload.lastName.trim() || firstName

  await submitEnterpriseApplication({
    session: true,
    firstName,
    lastName,
    email: payload.email.trim(),
    companyName: payload.companyName.trim(),
    companyWebsite: payload.companyWebsite,
    companySize: CONSOLE_CUSTOMER_STORY_ENTERPRISE_DEFAULTS.companySize,
    preferredDeployment:
      CONSOLE_CUSTOMER_STORY_ENTERPRISE_DEFAULTS.preferredDeployment,
    timeline: CONSOLE_CUSTOMER_STORY_ENTERPRISE_DEFAULTS.timeline,
    useCase,
    cloudEmail: payload.cloudEmail?.trim() || payload.email.trim(),
  })
}

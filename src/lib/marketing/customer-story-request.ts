import {
  isGrowthFormsConfigured,
  submitEnterpriseApplication,
} from '@/lib/marketing/growth-forms'

export { isGrowthFormsConfigured }

export type CustomerStoryInterviewPayload = {
  firstName: string
  lastName: string
  email: string
  companyName: string
  companyWebsite: string
  storySummary: string
  cloudEmail?: string
}

/** Growth `/conversations/enterprises` requires these strings; not collected in the feedback popover. */
const CONSOLE_CUSTOMER_STORY_ENTERPRISE_DEFAULTS = {
  companySize: 'Not specified (console customer story)',
  preferredDeployment: 'Appwrite Cloud',
  timeline: 'Just researching',
} as const

/** Growth sales conversation API (same as `/sales` wizard). */
export async function submitCustomerStoryInterviewRequest(
  payload: CustomerStoryInterviewPayload,
): Promise<boolean> {
  const useCase = [
    'Customer story interview request (console feedback)',
    '',
    payload.storySummary.trim(),
  ].join('\n')

  const firstName = payload.firstName.trim() || 'Unknown'
  const lastName = payload.lastName.trim() || firstName

  return submitEnterpriseApplication({
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

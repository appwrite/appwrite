import { getRuntimeConfig } from '@/lib/runtime-config'

const GROWTH_ENDPOINT = getRuntimeConfig().growthEndpoint

function getGrowthBaseUrl(): string | null {
  const trimmed = GROWTH_ENDPOINT?.trim()
  if (!trimmed) return null
  return trimmed.replace(/\/$/, '')
}

function getReferrerAndUtmSource(): Record<string, string | undefined> {
  if (typeof window === 'undefined') return {}
  const params = new URLSearchParams(window.location.search)
  return {
    referrer: document.referrer || undefined,
    utmSource: params.get('utm_source') ?? undefined,
    utmMedium: params.get('utm_medium') ?? undefined,
    utmCampaign: params.get('utm_campaign') ?? undefined,
  }
}

async function postGrowthJson(path: string, body: Record<string, unknown>): Promise<boolean> {
  const baseUrl = getGrowthBaseUrl()
  if (!baseUrl) return false

  const response = await fetch(`${baseUrl}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...body, ...getReferrerAndUtmSource() }),
  })

  if (response.status >= 400) {
    throw new Error(
      response.status >= 500
        ? 'Internal server error.'
        : 'Error submitting form. Please contact support.',
    )
  }

  return true
}

export function isGrowthFormsConfigured(): boolean {
  return !!getGrowthBaseUrl()
}

export type PartnerApplicationPayload = {
  name: string
  email: string
  companyName: string
  companyUrl: string
  message: string
}

export async function submitPartnerApplication(
  payload: PartnerApplicationPayload,
): Promise<boolean> {
  return postGrowthJson('/conversations/partner', payload)
}

export type StartupsApplicationPayload = {
  personName: string
  personEmail: string
  companyName: string
  companyUrl: string
}

export async function submitStartupsApplication(
  payload: StartupsApplicationPayload,
): Promise<boolean> {
  const companyUrl = payload.companyUrl.startsWith('http')
    ? payload.companyUrl
    : `https://${payload.companyUrl}`

  return postGrowthJson('/conversations/startups', {
    ...payload,
    companyUrl,
  })
}

export type EnterpriseApplicationPayload = {
  firstName: string
  lastName: string
  email: string
  companyName: string
  companySize?: string
  companyWebsite: string
  preferredDeployment?: string
  timeline?: string
  useCase: string
  cloudEmail?: string
}

export async function submitEnterpriseApplication(
  payload: EnterpriseApplicationPayload,
): Promise<boolean> {
  const companyWebsite = payload.companyWebsite.startsWith('http')
    ? payload.companyWebsite
    : `https://${payload.companyWebsite}`

  return postGrowthJson('/conversations/enterprises', {
    firstName: payload.firstName,
    lastName: payload.lastName,
    email: payload.email,
    message: payload.useCase,
    companyName: payload.companyName,
    companySize: payload.companySize,
    companyWebsite,
    preferredDeployment: payload.preferredDeployment,
    timeline: payload.timeline,
    cloudEmail: payload.cloudEmail,
    platform: 'appwrite',
  })
}

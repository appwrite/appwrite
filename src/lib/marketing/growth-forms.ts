import { getRuntimeConfig } from '@/lib/runtime-config'
import { getReferrerAndUtmSource } from '@/lib/marketing/utm'

const GROWTH_ENDPOINT = getRuntimeConfig().growthEndpoint

function getGrowthBaseUrl(): string | null {
  const trimmed = GROWTH_ENDPOINT?.trim()
  if (!trimmed) return null
  return trimmed.replace(/\/$/, '')
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
    let apiMessage: string | undefined
    try {
      const data = (await response.json()) as { message?: unknown }
      if (typeof data.message === 'string' && data.message.trim()) {
        apiMessage = data.message.trim()
      }
    } catch {
      // Non-JSON error body
    }

    if (response.status === 429) {
      throw new Error('Too many requests. Try again in a few minutes.')
    }

    throw new Error(
      response.status >= 500
        ? 'Internal server error.'
        : apiMessage ?? 'Error submitting form. Please contact support.',
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
  companySize?: string | null
  companyWebsite: string
  preferredDeployment?: string | null
  timeline?: string | null
  useCase: string
  cloudEmail?: string | null
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
    companySize: payload.companySize ?? null,
    companyWebsite,
    preferredDeployment: payload.preferredDeployment ?? null,
    timeline: payload.timeline ?? null,
    cloudEmail: payload.cloudEmail ?? null,
    platform: 'appwrite',
  })
}

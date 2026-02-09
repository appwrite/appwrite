/**
 * Support ticket submission to the Growth server.
 * Does not use the Appwrite SDK; uses plain fetch to VITE_GROWTH_ENDPOINT/support.
 * If VITE_GROWTH_ENDPOINT is not set, submission is skipped (no request is sent).
 *
 * Main support flow: multipart/form-data with email, subject, firstName, message,
 * tags[], customFields (JSON string), optional attachment.
 */

/** Set VITE_GROWTH_ENDPOINT in .env (e.g. https://growth.example.com) */
const GROWTH_ENDPOINT = import.meta.env.VITE_GROWTH_ENDPOINT as
  | string
  | undefined

/** Custom field IDs used by the Growth support API (main support form) */
export const SUPPORT_CUSTOM_FIELDS = {
  /** Category: general, billing, or technical */
  CATEGORY: '41612',
  /** Organization ID */
  ORGANIZATION_ID: '48492',
  /** Project ID (optional, for ticket context) */
  PROJECT: '48491',
  /** Severity: critical, high, medium, low */
  SEVERITY: '56023',
  /** Billing plan ID */
  BILLING_PLAN: '56024',
} as const

export const SUPPORT_ANALYTICS_EVENT = 'submit_support_ticket' as const

const FIRSTNAME_MAX_LENGTH = 40
const SUBJECT_MAX_LENGTH = 128
const MESSAGE_MAX_LENGTH = 4096
const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024 // 5 MB

export type SupportCategory = 'general' | 'billing' | 'technical'
export type SupportSeverity = 'critical' | 'high' | 'medium' | 'low'

/** Topic slugs per category (lowercase, for tag) */
export const SUPPORT_TOPICS: Record<
  SupportCategory,
  { value: string; label: string }[]
> = {
  general: [
    { value: 'security', label: 'Security' },
    { value: 'compliance', label: 'Compliance' },
    { value: 'performance', label: 'Performance' },
    { value: 'account', label: 'Account' },
    { value: 'project', label: 'Project' },
    { value: 'regions', label: 'Regions' },
    { value: 'other', label: 'Other' },
  ],
  billing: [
    { value: 'invoice', label: 'Invoice' },
    { value: 'plans', label: 'Plans' },
    { value: 'payment-methods', label: 'Payment methods' },
    { value: 'downgrade', label: 'Downgrade' },
    { value: 'refund', label: 'Refund' },
    { value: 'usage', label: 'Usage' },
    { value: 'other', label: 'Other' },
  ],
  technical: [
    { value: 'auth', label: 'Auth' },
    { value: 'databases', label: 'Databases' },
    { value: 'storage', label: 'Storage' },
    { value: 'functions', label: 'Functions' },
    { value: 'realtime', label: 'Realtime' },
    { value: 'messaging', label: 'Messaging' },
    { value: 'migrations', label: 'Migrations' },
    { value: 'webhooks', label: 'Webhooks' },
    { value: 'sdks', label: 'SDKs' },
    { value: 'console', label: 'Console' },
    { value: 'backups', label: 'Backups' },
    { value: 'blocked-project', label: 'Blocked project' },
    { value: 'domains', label: 'Domains' },
    { value: 'outage', label: 'Outage' },
    { value: 'platforms', label: 'Platforms' },
    { value: 'sites', label: 'Sites' },
    { value: 'other', label: 'Other' },
  ],
}

export interface SubmitSupportTicketParams {
  email: string
  subject: string
  firstName: string
  message: string
  category: SupportCategory
  topic: string
  severity: SupportSeverity
  organizationId: string
  projectId?: string
  billingPlanId?: string
  attachment?: File
}

/**
 * Submits a support ticket to the Growth server via FormData.
 * Tag format: {category}-{topic} (lowercase). If no topic, only {category}.
 * On non-200 response, throws; caller should track error analytics.
 *
 * @returns true if request was sent and status was 200, false if endpoint not configured
 */
export async function submitSupportTicket(
  params: SubmitSupportTicketParams,
): Promise<boolean> {
  if (!GROWTH_ENDPOINT?.trim()) {
    return false
  }

  const firstName = params.firstName.slice(0, FIRSTNAME_MAX_LENGTH) || 'Unknown'
  const subject = params.subject.slice(0, SUBJECT_MAX_LENGTH)
  const message = params.message.slice(0, MESSAGE_MAX_LENGTH)

  if (params.attachment && params.attachment.size > ATTACHMENT_MAX_BYTES) {
    throw new Error('Attachment must be 5 MB or less')
  }

  const tag =
    params.topic && params.topic !== 'other'
      ? `${params.category}-${params.topic}`
      : params.category

  const customFields = [
    { id: SUPPORT_CUSTOM_FIELDS.CATEGORY, value: params.category },
    { id: SUPPORT_CUSTOM_FIELDS.ORGANIZATION_ID, value: params.organizationId },
    { id: SUPPORT_CUSTOM_FIELDS.PROJECT, value: params.projectId ?? '' },
    { id: SUPPORT_CUSTOM_FIELDS.SEVERITY, value: params.severity },
    {
      id: SUPPORT_CUSTOM_FIELDS.BILLING_PLAN,
      value: params.billingPlanId ?? '',
    },
  ]

  const form = new FormData()
  form.append('email', params.email)
  form.append('subject', subject)
  form.append('firstName', firstName)
  form.append('message', message)
  form.append('tags[]', tag)
  form.append('customFields', JSON.stringify(customFields))

  if (params.attachment) {
    form.append('attachment', params.attachment)
  }

  const baseUrl = GROWTH_ENDPOINT.replace(/\/$/, '')
  const response = await fetch(`${baseUrl}/support`, {
    method: 'POST',
    body: form,
    // Do not set Content-Type; browser sets multipart boundary
  })

  if (response.status !== 200) {
    throw new Error('Failed to submit support ticket')
  }

  return true
}

/**
 * Track support ticket submit (success). Call after submitSupportTicket returns true.
 * On non-200, caller should track the same event as error (e.g. submit_support_ticket with error).
 */
export function getSupportAnalyticsEvent(): string {
  return SUPPORT_ANALYTICS_EVENT
}

const SUPPORT_TIMEZONE = 'Europe/Paris' // CET/CEST

/** Support hours: Mon–Fri 17:00 – 01:00 CET (weekdays only). Informational only; submission always allowed. */
export function getSupportHoursInLocalTime(): {
  startLocal: string
  endLocal: string
  isOpen: boolean
  timezone: string
} {
  const now = new Date()
  const month = now.getMonth()
  const isCEST = month >= 2 && month <= 9
  const cetOffset = isCEST ? 2 : 1
  const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  })
  // Display: 17:00 and 01:00 CET converted to user's local time (same day as "now" for consistency)
  const startCET = new Date(now)
  startCET.setUTCHours(17 - cetOffset, 0, 0, 0)
  const endCET = new Date(now)
  endCET.setUTCHours(25 - cetOffset, 0, 0, 0) // 01:00 next day CET
  const startLocal = timeFormatter.format(startCET)
  const endLocal = timeFormatter.format(endCET)
  // isOpen: only true on weekdays Mon–Fri 17:00–01:00 CET (including Sat 00:00–00:59 as tail of Fri)
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: SUPPORT_TIMEZONE,
    weekday: 'long',
    hour: '2-digit',
    hour12: false,
  }).formatToParts(now)
  const part = (k: string) => parts.find((p) => p.type === k)?.value ?? ''
  const cetWeekday = part('weekday')
  const cetHour = parseInt(part('hour'), 10)
  const weekdayNum: Record<string, number> = {
    Monday: 1,
    Tuesday: 2,
    Wednesday: 3,
    Thursday: 4,
    Friday: 5,
    Saturday: 6,
    Sunday: 0,
  }
  const w = weekdayNum[cetWeekday] ?? 0
  const isOpen =
    (w >= 1 && w <= 4 && cetHour >= 17) ||
    (w >= 2 && w <= 5 && cetHour < 1) ||
    (w === 5 && cetHour >= 17) ||
    (w === 6 && cetHour < 1)
  return {
    startLocal,
    endLocal,
    isOpen,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  }
}

export const SEVERITY_OPTIONS: {
  value: SupportSeverity
  label: string
  description: string
}[] = [
  { value: 'low', label: 'Low', description: 'Minor issue' },
  {
    value: 'medium',
    label: 'Medium',
    description: 'Significant issue with partial workaround',
  },
  {
    value: 'high',
    label: 'High',
    description: 'Major functionality impaired, workaround available',
  },
  {
    value: 'critical',
    label: 'Critical',
    description: 'System down or major outage',
  },
]

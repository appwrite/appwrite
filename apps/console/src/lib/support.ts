/**
 * Support tickets, filed as growth conversations. The server reads the email,
 * name and billing plan from the console session and organization.
 */

import { ConversationType, createConversation } from '@/lib/growth'

export const SUPPORT_ANALYTICS_EVENT = 'submit_support_ticket' as const

const SUBJECT_MAX_LENGTH = 128
const MESSAGE_MAX_LENGTH = 4096
const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024 // 5 MB

export interface SubmitSupportTicketParams {
  /** Used only without a console session. */
  email: string
  name: string
  subject: string
  message: string
  organizationId: string
  projectId?: string
  attachment?: File
}

/**
 * Submits a support ticket as one multipart request when there is an attachment.
 *
 * @throws Error when the attachment is over 5 MB, GrowthError when the server rejects the request.
 */
export async function submitSupportTicket(
  params: SubmitSupportTicketParams,
): Promise<void> {
  if (params.attachment && params.attachment.size > ATTACHMENT_MAX_BYTES) {
    throw new Error('Attachment must be 5 MB or less')
  }

  await createConversation({
    type: ConversationType.Support,
    email: params.email,
    name: params.name,
    subject: params.subject.slice(0, SUBJECT_MAX_LENGTH),
    message: params.message.slice(0, MESSAGE_MAX_LENGTH),
    organizationId: params.organizationId,
    projectId: params.projectId,
    attachment: params.attachment,
  })
}

/**
 * Track support ticket submit (success). Call after submitSupportTicket resolves.
 * On failure, caller should track the same event as error (e.g. submit_support_ticket with error).
 */
export function getSupportAnalyticsEvent(): string {
  return SUPPORT_ANALYTICS_EVENT
}

const SUPPORT_TIMEZONE = 'Europe/Paris' // CET/CEST

/** Support hours: Mon–Fri 14:00 – 02:00 CET, 12 hours (weekdays only). Informational only; submission always allowed. */
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
  // Display: 14:00 and 02:00 CET converted to user's local time (same day as "now" for consistency)
  const startCET = new Date(now)
  startCET.setUTCHours(14 - cetOffset, 0, 0, 0)
  const endCET = new Date(now)
  endCET.setUTCHours(26 - cetOffset, 0, 0, 0) // 02:00 next day CET
  const startLocal = timeFormatter.format(startCET)
  const endLocal = timeFormatter.format(endCET)
  // isOpen: only true on weekdays Mon–Fri 14:00–02:00 CET (including Sat 00:00–01:59 as tail of Fri)
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
    (w >= 1 && w <= 4 && cetHour >= 14) ||
    (w >= 2 && w <= 5 && cetHour < 2) ||
    (w === 5 && cetHour >= 14) ||
    (w === 6 && cetHour < 2)
  return {
    startLocal,
    endLocal,
    isOpen,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  }
}

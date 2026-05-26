import {
  INIT_TICKET_DEFAULT_STACK,
  parseInitTicketStack,
  type InitTicketStackId,
} from '@/lib/init/ticket-stack'

export const INIT_TICKET_PREFS_KEY_PREFIX = 'console.init.ticket'

export interface InitTicketPrefs {
  displayName?: string
  holderTitle?: string
  stack: InitTicketStackId[]
}

export const DEFAULT_INIT_TICKET_PREFS: InitTicketPrefs = {
  stack: [...INIT_TICKET_DEFAULT_STACK],
}

export function getInitTicketPrefsAccountKey(eventId: string): string {
  return `${INIT_TICKET_PREFS_KEY_PREFIX}.${eventId}`
}

export function getInitTicketPrefsStorageKey(
  eventId: string,
  userId: string,
): string {
  return `${INIT_TICKET_PREFS_KEY_PREFIX}.v1.${eventId}.${userId}`
}

export function parseInitTicketPrefs(value: unknown): InitTicketPrefs | null {
  if (!value || typeof value !== 'object') return null
  const record = value as Record<string, unknown>
  const displayName =
    typeof record.displayName === 'string' ? record.displayName.trim() : undefined
  const holderTitle =
    typeof record.holderTitle === 'string' ? record.holderTitle.trim() : undefined
  const stack = parseInitTicketStack(record.stack)
  return {
    stack,
    ...(displayName ? { displayName } : {}),
    ...(holderTitle ? { holderTitle } : {}),
  }
}

export function getInitTicketHolderTitle(
  prefs: InitTicketPrefs,
  defaultTitle: string,
): string {
  return prefs.holderTitle?.trim() || defaultTitle
}

export function readInitTicketPrefsFromStorage(
  eventId: string,
  userId: string,
): InitTicketPrefs | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(
      getInitTicketPrefsStorageKey(eventId, userId),
    )
    if (!raw) return null
    return parseInitTicketPrefs(JSON.parse(raw))
  } catch {
    return null
  }
}

export function writeInitTicketPrefsToStorage(
  eventId: string,
  userId: string,
  prefs: InitTicketPrefs,
): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      getInitTicketPrefsStorageKey(eventId, userId),
      JSON.stringify(prefs),
    )
  } catch {
    /* private mode */
  }
}

export function readInitTicketPrefsFromAccountPrefs(
  accountPrefs: Record<string, unknown> | undefined,
  eventId: string,
): InitTicketPrefs | null {
  if (!accountPrefs) return null
  const raw = accountPrefs[getInitTicketPrefsAccountKey(eventId)]
  if (typeof raw === 'string') {
    try {
      return parseInitTicketPrefs(JSON.parse(raw))
    } catch {
      return null
    }
  }
  return parseInitTicketPrefs(raw)
}

export function mergeInitTicketPrefsIntoAccountPrefs(
  existingPrefs: Record<string, unknown> | undefined,
  eventId: string,
  ticketPrefs: InitTicketPrefs,
): Record<string, unknown> {
  return {
    ...(existingPrefs ?? {}),
    [getInitTicketPrefsAccountKey(eventId)]: JSON.stringify(ticketPrefs),
  }
}

export function formatInitTicketNumber(userId?: string | null): string {
  if (!userId) return '#INIT-000000'
  const suffix = userId.replace(/[^a-z0-9]/gi, '').slice(-6).toUpperCase()
  return `#INIT-${suffix.padStart(6, '0')}`
}

export function buildInitTicketShareMessage(params: {
  eventName: string
  dateRangeLabel: string
  holderName: string
  shareUrl: string
}): string {
  return `I'm going to ${params.eventName} (${params.dateRangeLabel}) as ${params.holderName}. One ticket holder wins exclusive Init swag on the last day - claim your pass: ${params.shareUrl}`
}

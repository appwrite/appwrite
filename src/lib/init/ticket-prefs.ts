import {
  parseInitTicketStack,
  type InitTicketStackId,
} from '@/lib/init/ticket-stack'

export const INIT_TICKET_PREFS_KEY_PREFIX = 'console.init.ticket'

export type InitTicketAccent = 'brand' | 'rose' | 'cyan' | 'amber' | 'violet'

export type InitTicketPassLabel = 'Init pass' | 'Launch crew' | 'Builder pass'

export interface InitTicketPrefs {
  accent: InitTicketAccent
  passLabel: InitTicketPassLabel
  displayName?: string
  stack: InitTicketStackId[]
}

export const INIT_TICKET_ACCENT_OPTIONS: {
  id: InitTicketAccent
  label: string
  color: string
}[] = [
  { id: 'brand', label: 'Brand', color: 'var(--brand-cta)' },
  { id: 'rose', label: 'Rose', color: '#ff6b9d' },
  { id: 'cyan', label: 'Cyan', color: '#22d3ee' },
  { id: 'amber', label: 'Amber', color: '#fbbf24' },
  { id: 'violet', label: 'Violet', color: '#a78bfa' },
]

export const INIT_TICKET_PASS_LABELS: InitTicketPassLabel[] = [
  'Init pass',
  'Launch crew',
  'Builder pass',
]

export const DEFAULT_INIT_TICKET_PREFS: InitTicketPrefs = {
  accent: 'brand',
  passLabel: 'Init pass',
  stack: [],
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
  const accent = record.accent
  const passLabel = record.passLabel
  if (
    typeof accent !== 'string' ||
    !INIT_TICKET_ACCENT_OPTIONS.some((option) => option.id === accent)
  ) {
    return null
  }
  if (
    typeof passLabel !== 'string' ||
    !INIT_TICKET_PASS_LABELS.includes(passLabel as InitTicketPassLabel)
  ) {
    return null
  }
  const displayName =
    typeof record.displayName === 'string' ? record.displayName.trim() : undefined
  const stack = parseInitTicketStack(record.stack)
  return {
    accent: accent as InitTicketAccent,
    passLabel: passLabel as InitTicketPassLabel,
    stack,
    ...(displayName ? { displayName } : {}),
  }
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

export function getInitTicketAccentColor(accent: InitTicketAccent): string {
  return (
    INIT_TICKET_ACCENT_OPTIONS.find((option) => option.id === accent)?.color ??
    'var(--brand-cta)'
  )
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

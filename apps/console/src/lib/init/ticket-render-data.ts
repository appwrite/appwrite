import type { Models } from '@appwrite.io/console'
import {
  formatInitTicketNumber,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'
import {
  resolveInitTicketAppearance,
  type InitTicketTypeId,
  type ResolvedInitTicketAppearance,
} from '@/lib/init/ticket-types'
import type { InitDisplayEvent } from '@/lib/init/types'

type InitTicketRenderEvent = Pick<
  InitDisplayEvent,
  'dateRangeLabel' | 'tickets'
>

export interface InitTicketRenderData {
  dateRangeLabel: string
  holderName: string
  ticketNumber: string
  prefs: InitTicketPrefs
  ticketAppearance: ResolvedInitTicketAppearance
}

export function getInitTicketAccountName(
  account: Models.User | null | undefined,
): string | undefined {
  return account?.name?.trim() || account?.email?.split('@')[0] || undefined
}

export function getInitTicketHolderName(
  accountName: string | undefined,
  prefs: InitTicketPrefs,
  fallback = 'Your name',
): string {
  return prefs.displayName?.trim() || accountName?.trim() || fallback
}

export function getInitTicketNumberForUser(userId?: string | null): string {
  return formatInitTicketNumber(userId)
}

export function buildInitTicketRenderData(params: {
  event: InitTicketRenderEvent
  account?: Models.User | null
  identities?: Models.Identity[]
  prefs: InitTicketPrefs
  themeUsesDarkImage: boolean
  mockTypeId?: InitTicketTypeId | null
  fallbackHolderName?: string
  ticketNumber?: string
}): InitTicketRenderData {
  const accountName = getInitTicketAccountName(params.account)
  const holderName = getInitTicketHolderName(
    accountName,
    params.prefs,
    params.fallbackHolderName,
  )
  const ticketAppearance = resolveInitTicketAppearance(
    params.event.tickets,
    { account: params.account, identities: params.identities },
    params.themeUsesDarkImage,
    params.mockTypeId,
  )

  return {
    dateRangeLabel: params.event.dateRangeLabel,
    holderName,
    ticketNumber:
      params.ticketNumber ?? getInitTicketNumberForUser(params.account?.$id),
    prefs: params.prefs,
    ticketAppearance,
  }
}

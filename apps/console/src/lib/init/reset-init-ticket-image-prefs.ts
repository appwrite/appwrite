import type { QueryClient } from '@tanstack/react-query'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  DEFAULT_INIT_TICKET_PREFS,
  mergeInitTicketPrefsIntoAccountPrefs,
  notifyInitTicketPrefsChange,
  readInitTicketPrefsFromAccountPrefs,
  readInitTicketPrefsFromStorage,
  stripInitTicketImageFromPrefs,
  writeInitTicketPrefsToStorage,
  type InitTicketPrefs,
} from '@/lib/init/ticket-prefs'

export async function resetInitTicketImagePrefs(params: {
  eventId: string
  userId: string
  accountPrefs?: Record<string, unknown>
  queryClient?: QueryClient
}): Promise<InitTicketPrefs> {
  const current =
    readInitTicketPrefsFromAccountPrefs(params.accountPrefs, params.eventId) ??
    readInitTicketPrefsFromStorage(params.eventId, params.userId) ??
    DEFAULT_INIT_TICKET_PREFS

  const next = stripInitTicketImageFromPrefs(current)

  writeInitTicketPrefsToStorage(params.eventId, params.userId, next)

  const updatedAccount = await updateAccountPrefs(
    mergeInitTicketPrefsIntoAccountPrefs(
      params.accountPrefs,
      params.eventId,
      next,
    ),
  )

  if (params.queryClient) {
    syncConsoleAccountAfterMutation(params.queryClient, {
      apiResult: updatedAccount,
    })
  }

  notifyInitTicketPrefsChange({
    eventId: params.eventId,
    userId: params.userId,
    prefs: next,
  })

  return next
}

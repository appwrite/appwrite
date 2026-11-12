import { useCallback, useEffect, useRef, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_INIT_TICKET_PREFS,
  INIT_TICKET_PREFS_CHANGE_EVENT,
  mergeInitTicketPrefsIntoAccountPrefs,
  readInitTicketGuestPrefsFromStorage,
  readInitTicketPrefsFromAccountPrefs,
  readInitTicketPrefsFromStorage,
  writeInitTicketGuestPrefsToStorage,
  writeInitTicketPrefsToStorage,
  type InitTicketPrefs,
  type InitTicketPrefsChangeDetail,
} from '@/lib/init/ticket-prefs'

const SAVE_DEBOUNCE_MS = 600

export function useInitTicketPrefs(
  eventId: string,
  account?: Models.User | null,
) {
  const userId = account?.$id
  const [prefs, setPrefs] = useState<InitTicketPrefs>(DEFAULT_INIT_TICKET_PREFS)
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (!userId) {
      setPrefs(
        readInitTicketGuestPrefsFromStorage(eventId) ?? DEFAULT_INIT_TICKET_PREFS,
      )
      return
    }

    const fromAccount = readInitTicketPrefsFromAccountPrefs(
      account?.prefs as Record<string, unknown> | undefined,
      eventId,
    )
    const fromStorage = readInitTicketPrefsFromStorage(eventId, userId)
    setPrefs(fromAccount ?? fromStorage ?? DEFAULT_INIT_TICKET_PREFS)
  }, [account?.prefs, eventId, userId])

  const persistPrefs = useCallback(
    (next: InitTicketPrefs) => {
      if (!userId) {
        writeInitTicketGuestPrefsToStorage(eventId, next)
        return
      }

      writeInitTicketPrefsToStorage(eventId, userId, next)

      if (saveTimerRef.current) {
        clearTimeout(saveTimerRef.current)
      }

      saveTimerRef.current = setTimeout(() => {
        void sdk.forConsole.account
          .updatePrefs({
            prefs: mergeInitTicketPrefsIntoAccountPrefs(
              account?.prefs as Record<string, unknown> | undefined,
              eventId,
              next,
            ),
          })
          .catch(() => {})
      }, SAVE_DEBOUNCE_MS)
    },
    [account?.prefs, eventId, userId],
  )

  const updatePrefs = useCallback(
    (patch: Partial<InitTicketPrefs>) => {
      setPrefs((current) => {
        const next = { ...current, ...patch }
        persistPrefs(next)
        return next
      })
    },
    [persistPrefs],
  )

  useEffect(() => {
    if (!userId || typeof window === 'undefined') return

    const handlePrefsChange = (event: Event) => {
      const detail = (event as CustomEvent<InitTicketPrefsChangeDetail>).detail
      if (detail.eventId !== eventId || detail.userId !== userId) return
      setPrefs(detail.prefs)
    }

    window.addEventListener(INIT_TICKET_PREFS_CHANGE_EVENT, handlePrefsChange)
    return () => {
      window.removeEventListener(
        INIT_TICKET_PREFS_CHANGE_EVENT,
        handlePrefsChange,
      )
    }
  }, [eventId, userId])

  useEffect(() => {
    return () => {
      if (saveTimerRef.current) {
        clearTimeout(saveTimerRef.current)
      }
    }
  }, [])

  return { prefs, updatePrefs }
}

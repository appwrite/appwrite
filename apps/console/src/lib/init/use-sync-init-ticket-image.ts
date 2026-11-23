import { useEffect, useRef } from 'react'
import type { Models } from '@appwrite.io/console'
import { buildInitTicketImageSignature } from '@/lib/init/init-ticket-image-signature'
import { isInitTicketStorageConfigured } from '@/lib/init/init-ticket-storage-config'
import type { InitTicketPrefs } from '@/lib/init/ticket-prefs'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'
import { syncInitTicketImage } from '@/lib/init/sync-init-ticket-image'

const SYNC_DEBOUNCE_MS = 1200

export function useSyncInitTicketImage(params: {
  eventSlug: string
  account?: Models.User | null
  prefs: InitTicketPrefs
  renderData: InitTicketRenderData
  themeUsesDarkImage: boolean
  updatePrefs: (patch: Partial<InitTicketPrefs>) => void
}) {
  const {
    eventSlug,
    account,
    prefs,
    renderData,
    themeUsesDarkImage,
    updatePrefs,
  } = params
  const updatePrefsRef = useRef(updatePrefs)
  updatePrefsRef.current = updatePrefs

  useEffect(() => {
    if (!isInitTicketStorageConfigured()) return

    const signature = buildInitTicketImageSignature(renderData)
    if (prefs.imageFileId && prefs.imageSignature === signature) return

    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void syncInitTicketImage({
        eventSlug,
        renderData,
        themeUsesDarkImage,
        consoleUserId: account?.$id,
        signal: controller.signal,
      })
        .then((result) => {
          if (
            prefs.imageFileId === result.fileId &&
            prefs.imageSignature === result.signature
          ) {
            return
          }
          updatePrefsRef.current({
            imageFileId: result.fileId,
            imageSignature: result.signature,
          })
        })
        .catch(() => {
          /* best-effort background sync */
        })
    }, SYNC_DEBOUNCE_MS)

    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [
    account?.$id,
    eventSlug,
    prefs.imageFileId,
    prefs.imageSignature,
    renderData,
    themeUsesDarkImage,
  ])
}

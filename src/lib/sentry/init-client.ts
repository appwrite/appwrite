import * as Sentry from '@sentry/tanstackstart-react'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { isStaleChunkLoadError } from '@/lib/stale-chunk-error'
import { isIndexedDBMutationError } from '@/lib/upload-queue/indexeddb'

let sentryInitialized = false

export function initSentryClient() {
  if (sentryInitialized || typeof window === 'undefined') return
  const sentryDsn = getRuntimeConfig().sentryDsn
  if (!sentryDsn) return

  Sentry.init({
    dsn: sentryDsn,
    sendDefaultPii: false,
    beforeSend(event, hint) {
      const err = hint.originalException
      if (err && typeof err === 'object') {
        const code = (err as { code?: number }).code
        const status = (err as { status?: number }).status
        if (code === 401 || status === 401) return null
      }
      if (isStaleChunkLoadError(err)) return null
      if (isIndexedDBMutationError(err)) return null
      return event
    },
  })
  sentryInitialized = true
}

import {
  getPlausibleInitScript,
  getPlausibleScriptSrc,
} from '@/lib/analytics'
import { deferAfterPaint } from '@/lib/defer-after-paint'
import { initSentryClient } from '@/lib/sentry/init-client'

let trackingScriptsLoaded = false

function appendScript(
  attributes: Record<string, string | boolean | undefined>,
  inlineContent?: string,
) {
  const script = document.createElement('script')
  for (const [key, value] of Object.entries(attributes)) {
    if (value === undefined || value === false) continue
    if (value === true) {
      script.setAttribute(key, '')
      continue
    }
    script.setAttribute(key, value)
  }
  if (inlineContent) {
    script.textContent = inlineContent
  }
  document.head.appendChild(script)
}

/** Loads Plausible and Sentry after analytics consent. */
export function loadTrackingScriptsAfterConsent() {
  if (typeof window === 'undefined') return

  deferAfterPaint(() => {
    if (!trackingScriptsLoaded) {
      trackingScriptsLoaded = true

      const plausibleScriptSrc = getPlausibleScriptSrc()
      if (plausibleScriptSrc) {
        appendScript({}, getPlausibleInitScript())
        appendScript({ src: plausibleScriptSrc, defer: 'true' })
      }
    }

    // Always attempt init (idempotent). Previously we skipped this once
    // trackingScriptsLoaded was set, so a failed/early call never retried.
    initSentryClient()
  })
}

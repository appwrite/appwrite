import {
  PLAUSIBLE_INIT_SCRIPT,
  PLAUSIBLE_SCRIPT_SRC,
} from '@/lib/analytics'
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

  if (!trackingScriptsLoaded) {
    trackingScriptsLoaded = true

    if (PLAUSIBLE_SCRIPT_SRC) {
      appendScript({}, PLAUSIBLE_INIT_SCRIPT)
      appendScript({ src: PLAUSIBLE_SCRIPT_SRC, async: 'true' })
    }
  }

  // Always attempt init (idempotent). Previously we skipped this once
  // trackingScriptsLoaded was set, so a failed/early call never retried.
  initSentryClient()
}

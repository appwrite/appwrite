import {
  flushPendingPageView,
  getPlausibleInitScript,
  getPlausibleScriptSrc,
} from '@/lib/analytics'
import { isCloudProfile } from '@/lib/console-profiles'

let plausibleLoaded = false

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

/** Cookieless usage analytics. Safe to call before cookie consent. */
export function loadPlausibleScript() {
  if (typeof window === 'undefined') return
  if (plausibleLoaded) return
  plausibleLoaded = true

  const plausibleScriptSrc = getPlausibleScriptSrc()
  if (!plausibleScriptSrc) return

  appendScript({}, getPlausibleInitScript())
  appendScript({ src: plausibleScriptSrc, defer: 'true' })
  flushPendingPageView()
}

function deferNonCriticalTracking(callback: () => void): void {
  if (typeof window === 'undefined') {
    callback()
    return
  }

  const requestIdleCallback = window.requestIdleCallback
  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(() => callback(), { timeout: 4000 })
    return
  }

  window.setTimeout(callback, 2500)
}

/** Sentry and ads pixel. Call only after analytics consent. */
export function loadTrackingScriptsAfterConsent() {
  if (typeof window === 'undefined') return

  deferNonCriticalTracking(() => {
    // Idempotent. A failed/early call must be allowed to retry.
    void import('@/lib/sentry/init-client').then((module) => {
      module.initSentryClient()
    })
    if (isCloudProfile()) {
      void import('@/lib/openai-ads').then((module) => {
        module.loadOpenAiAdsPixel()
      })
    }
  })
}

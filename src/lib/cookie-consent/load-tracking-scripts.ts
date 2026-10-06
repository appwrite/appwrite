import {
  flushPendingPageView,
  getPlausibleInitScript,
  getPlausibleScriptSrc,
} from '@/lib/analytics'
import { isCloudProfile } from '@/lib/console-profiles'
import { deferAfterPaint } from '@/lib/defer-after-paint'
import { loadOpenAiAdsPixel } from '@/lib/openai-ads'
import { initSentryClient } from '@/lib/sentry/init-client'

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

/** Sentry and ads pixel. Call only after analytics consent. */
export function loadTrackingScriptsAfterConsent() {
  if (typeof window === 'undefined') return

  deferAfterPaint(() => {
    // Idempotent. A failed/early call must be allowed to retry.
    initSentryClient()
    if (isCloudProfile()) {
      loadOpenAiAdsPixel()
    }
  })
}

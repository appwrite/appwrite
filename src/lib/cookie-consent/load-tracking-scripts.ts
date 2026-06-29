import {
  PLAUSIBLE_INIT_SCRIPT,
  PLAUSIBLE_SCRIPT_SRC,
} from '@/lib/analytics'
import { initSentryClient } from '@/lib/sentry/init-client'
import { getRuntimeConfig } from '@/lib/runtime-config'

let trackingScriptsLoaded = false

function normalizeInstrumentationModuleSrc(raw: string): string {
  const src = raw.trim()
  if (!src) return src
  if (/^(?:https?:)?\/\//.test(src)) return src
  if (src.startsWith('/')) return src
  const base = (import.meta.env.BASE_URL || '/').replace(/\/?$/, '/')
  try {
    return new URL(src, `http://tsr.local${base}`).pathname
  } catch {
    return `${base}${src.replace(/^\//, '')}`
  }
}

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

/** Loads Plausible, optional instrumentation, and Sentry after analytics consent. */
export function loadTrackingScriptsAfterConsent() {
  if (trackingScriptsLoaded || typeof window === 'undefined') return
  trackingScriptsLoaded = true

  if (PLAUSIBLE_SCRIPT_SRC) {
    appendScript({}, PLAUSIBLE_INIT_SCRIPT)
    appendScript({ src: PLAUSIBLE_SCRIPT_SRC, async: 'true' })
  }

  const instrumentationScriptSrc = getRuntimeConfig().instrumentationScriptSrc
  if (instrumentationScriptSrc) {
    appendScript({
      src: normalizeInstrumentationModuleSrc(instrumentationScriptSrc),
      type: 'module',
    })
  }

  initSentryClient()
}

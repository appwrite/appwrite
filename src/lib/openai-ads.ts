import { isCloudProfile } from '@/lib/console-profiles'
import { isScreenshotModeActive } from '@/lib/screenshot-mode'

const OPENAI_ADS_PIXEL_ID = 'CcDzUNounP4xmh6weGk33t'
const OPENAI_ADS_SCRIPT_SRC = 'https://bzrcdn.openai.com/sdk/oaiq.min.js'

const REGISTRATION_INTENT_KEY = 'console.openaiAds.registrationIntent'
const PENDING_REGISTRATION_KEY = 'console.openaiAds.pendingRegistrationUserId'
const COMPLETED_REGISTRATION_PREFIX = 'console.openaiAds.registrationCompleted.'
const PENDING_SUBSCRIPTION_KEY = 'console.openaiAds.pendingSubscriptionOrgId'
const COMPLETED_SUBSCRIPTION_PREFIX = 'console.openaiAds.subscriptionCreated.'

type OpenAiAdsQueue = ((...args: unknown[]) => void) & {
  q: unknown[][]
}

declare global {
  interface Window {
    oaiq?: OpenAiAdsQueue
  }
}

let openaiAdsPixelLoaded = false

/** OpenAI Ads pixel and conversions run on Appwrite Cloud only. */
function canUseOpenAiAds(): boolean {
  if (typeof window === 'undefined') return false
  if (!isCloudProfile()) return false
  if (isScreenshotModeActive()) return false
  return true
}

function appendScript(src: string) {
  const script = document.createElement('script')
  script.src = src
  script.async = true
  document.head.appendChild(script)
}

function readStorage(storage: Storage, key: string): string | null {
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

function writeStorage(storage: Storage, key: string, value: string) {
  try {
    storage.setItem(key, value)
  } catch {
    /* private mode */
  }
}

function removeStorage(storage: Storage, key: string) {
  try {
    storage.removeItem(key)
  } catch {
    /* private mode */
  }
}

function completedKey(userId: string) {
  return `${COMPLETED_REGISTRATION_PREFIX}${userId}`
}

function hasMeasuredRegistration(userId: string): boolean {
  return readStorage(localStorage, completedKey(userId)) === '1'
}

function markRegistrationMeasured(userId: string) {
  writeStorage(localStorage, completedKey(userId), '1')
  removeStorage(sessionStorage, PENDING_REGISTRATION_KEY)
  removeStorage(sessionStorage, REGISTRATION_INTENT_KEY)
}

/**
 * OpenAI Ads pixel. Call only after analytics consent. Cloud-only; skipped
 * in screenshot mode so captures do not fire ad events.
 */
export function loadOpenAiAdsPixel() {
  if (!canUseOpenAiAds()) return
  if (openaiAdsPixelLoaded) return

  openaiAdsPixelLoaded = true

  if (!window.oaiq) {
    const queue = function (...args: unknown[]) {
      queue.q.push(args)
    } as OpenAiAdsQueue
    queue.q = []
    window.oaiq = queue
    appendScript(OPENAI_ADS_SCRIPT_SRC)
    window.oaiq('init', {
      pixelId: OPENAI_ADS_PIXEL_ID,
      debug: import.meta.env.DEV,
    })
  }

  flushPendingOpenAiAdsConversions()
}

/** Remember that this tab started a sign-up (OAuth) so we can convert after return. */
export function markOpenAiAdsRegistrationIntent() {
  if (!canUseOpenAiAds()) return
  writeStorage(sessionStorage, REGISTRATION_INTENT_KEY, '1')
}

/**
 * Conversion for a completed console registration (sign-up + verified email).
 * Queues until the pixel loads if the visitor has not granted optional cookies yet.
 */
export function measureOpenAiAdsRegistrationCompleted(userId: string) {
  if (!userId) return
  if (!canUseOpenAiAds()) return
  if (hasMeasuredRegistration(userId)) return

  if (!window.oaiq) {
    writeStorage(sessionStorage, PENDING_REGISTRATION_KEY, userId)
    return
  }

  window.oaiq('measure', 'registration_completed', { type: 'customer_action' })
  markRegistrationMeasured(userId)
}

function flushPendingOpenAiAdsConversions() {
  const userId = readStorage(sessionStorage, PENDING_REGISTRATION_KEY)
  if (userId) measureOpenAiAdsRegistrationCompleted(userId)
  const organizationId = readStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY)
  if (organizationId) measureOpenAiAdsSubscriptionCreated(organizationId)
}

function hasMeasuredSubscription(organizationId: string): boolean {
  return (
    readStorage(localStorage, COMPLETED_SUBSCRIPTION_PREFIX + organizationId) ===
    '1'
  )
}

function markSubscriptionMeasured(organizationId: string) {
  writeStorage(
    localStorage,
    COMPLETED_SUBSCRIPTION_PREFIX + organizationId,
    '1',
  )
  removeStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY)
}

/**
 * Conversion for enrolling in a paid plan (upgrade or paid org create).
 * Queues until the pixel loads if the visitor has not granted optional cookies yet.
 */
export function measureOpenAiAdsSubscriptionCreated(organizationId: string) {
  if (!organizationId) return
  if (!canUseOpenAiAds()) return
  if (hasMeasuredSubscription(organizationId)) return

  if (!window.oaiq) {
    writeStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY, organizationId)
    return
  }

  window.oaiq('measure', 'subscription_created', { type: 'plan_enrollment' })
  markSubscriptionMeasured(organizationId)
}

/**
 * OAuth sign-up returns to the console with a verified email. Fire only when
 * this tab started on the sign-up page and verification is already satisfied.
 */
export function maybeMeasureOpenAiAdsRegistrationAfterAuth(account: {
  $id: string
  emailVerification: boolean
} | null | undefined) {
  if (!canUseOpenAiAds()) return
  if (!account) return
  if (!account.emailVerification) return
  if (readStorage(sessionStorage, REGISTRATION_INTENT_KEY) !== '1') return
  measureOpenAiAdsRegistrationCompleted(account.$id)
}

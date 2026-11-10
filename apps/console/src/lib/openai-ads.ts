import { getConsoleAccountSync } from '@/lib/console-account-cache'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import {
  getConsoleAccountQueryRevision,
  isConsoleImpersonationActive,
} from '@/lib/console-impersonation'
import { isCloudProfile } from '@/lib/console-profiles'
import { isScreenshotModeActive } from '@/lib/screenshot-mode'

const OPENAI_ADS_PIXEL_ID = 'CcDzUNounP4xmh6weGk33t'
const OPENAI_ADS_SCRIPT_SRC = 'https://bzrcdn.openai.com/sdk/oaiq.min.js'

const REGISTRATION_INTENT_KEY = 'console.openaiAds.registrationIntent'
const PENDING_REGISTRATION_KEY = 'console.openaiAds.pendingRegistrationUserId'
const COMPLETED_REGISTRATION_PREFIX = 'console.openaiAds.registrationCompleted.'
const PENDING_SUBSCRIPTION_KEY = 'console.openaiAds.pendingSubscriptionOrgId'
const COMPLETED_SUBSCRIPTION_PREFIX = 'console.openaiAds.subscriptionCreated.'
const COMPLETED_LEAD_PREFIX = 'console.openaiAds.leadCreated.'
const PENDING_CHECKOUT_KEY = 'console.openaiAds.pendingCheckout'
const COMPLETED_CHECKOUT_PREFIX = 'console.openaiAds.checkoutStarted.'

/** `lead_created` only counts what an account builds in its first week. */
const LEAD_WINDOW_MS = 7 * 24 * 60 * 60 * 1000
/** Product areas a new account must touch to count as an activated builder. */
const LEAD_MIN_AREAS = 3
/** Navigation re-checks the lead signal at most this often. */
const LEAD_CHECK_INTERVAL_MS = 2 * 60 * 1000

type OpenAiAdsQueue = ((...args: unknown[]) => void) & {
  q: unknown[][]
}

declare global {
  interface Window {
    oaiq?: OpenAiAdsQueue
  }
}

let openaiAdsPixelLoaded = false
let pixelReady: Promise<void> | null = null
let attachedUserKey: string | null = null
let hintedIdentity: OpenAiAdsIdentity | undefined
let leadCheckedAt = 0
const registrationInFlight = new Set<string>()
const subscriptionInFlight = new Set<string>()
const leadInFlight = new Set<string>()
const checkoutInFlight = new Set<string>()

type OpenAiAdsIdentity = {
  externalId: string
  email?: string | null
  phone?: string | null
}

export type OpenAiAdsUserData = {
  email_sha256?: string
  external_id_sha256?: string
  phone_number_sha256?: string
}

/**
 * Hashed customer match keys for `oaiq("init", { user })`.
 * Normalization follows the Measurement Pixel user-data rules: trimmed
 * lowercase email, trimmed external id (case preserved), and digits-only phone.
 */
export async function buildOpenAiAdsUserData(
  identity: OpenAiAdsIdentity,
): Promise<OpenAiAdsUserData | undefined> {
  const user: OpenAiAdsUserData = {}

  const externalId = identity.externalId.trim()
  if (externalId) {
    const hash = await sha256Hex(externalId)
    if (hash) user.external_id_sha256 = hash
  }

  const email = identity.email?.trim().toLowerCase()
  if (email) {
    const hash = await sha256Hex(email)
    if (hash) user.email_sha256 = hash
  }

  const phone = normalizeOpenAiAdsPhone(identity.phone)
  if (phone) {
    const hash = await sha256Hex(phone)
    if (hash) user.phone_number_sha256 = hash
  }

  if (
    !user.external_id_sha256 &&
    !user.email_sha256 &&
    !user.phone_number_sha256
  ) {
    return undefined
  }
  return user
}

/** `+1 (415) 555-2671` becomes `14155552671`. Omit values that are not 8–15 digits. */
function normalizeOpenAiAdsPhone(
  phone: string | null | undefined,
): string | undefined {
  if (!phone) return undefined
  const digits = phone
    .replace(/[\s().-]/g, '')
    .replace(/^\+/, '')
    .replace(/^0+/, '')
  if (!/^\d{8,15}$/.test(digits)) return undefined
  return digits
}

async function sha256Hex(value: string): Promise<string | undefined> {
  if (!globalThis.crypto?.subtle) return undefined
  const digest = await crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(value),
  )
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')
}

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
  installOpenAiAdsQueue()

  pixelReady = initOpenAiAdsPixel()
  void pixelReady.then(() => {
    flushPendingOpenAiAdsConversions()
  })
}

function installOpenAiAdsQueue() {
  if (window.oaiq) return
  const queue = function (...args: unknown[]) {
    queue.q.push(args)
  } as OpenAiAdsQueue
  queue.q = []
  window.oaiq = queue
  appendScript(OPENAI_ADS_SCRIPT_SRC)
}

async function initOpenAiAdsPixel() {
  const hintAtStart = hintedIdentity
  let user = await resolveOpenAiAdsUserData(hintAtStart)
  // Sign-in can land while the account lookup is in flight. Use that identity
  // for the first init so the conversion is not queued ahead of the hashes.
  if (hintedIdentity && hintedIdentity !== hintAtStart) {
    user = (await resolveOpenAiAdsUserData(hintedIdentity)) ?? user
  }
  window.oaiq?.('init', {
    pixelId: OPENAI_ADS_PIXEL_ID,
    debug: import.meta.env.DEV,
    ...(user ? { user } : {}),
  })
  if (user) attachedUserKey = JSON.stringify(user)
}

/**
 * Signed-in account for advanced matching. Safe before the pixel loads: the
 * next init picks it up. After init, sends an updated `user` object.
 * Raw email and phone never leave the browser; only SHA-256 hashes are queued.
 */
export function syncOpenAiAdsUser(
  account:
    | {
        $id?: string
        email?: string | null
        phone?: string | null
        impersonatorUserId?: string | null
      }
    | null
    | undefined,
) {
  if (!account?.$id) return
  if (!canUseOpenAiAds()) return
  if (isConsoleImpersonationActive(account)) return

  hintedIdentity = {
    externalId: account.$id,
    email: account.email,
    phone: account.phone,
  }
  if (!pixelReady) return
  void pixelReady.then(() => attachOpenAiAdsUser(hintedIdentity))
}

/** Remember that this tab started a sign-up (OAuth) so we can convert after return. */
export function markOpenAiAdsRegistrationIntent() {
  if (!canUseOpenAiAds()) return
  writeStorage(sessionStorage, REGISTRATION_INTENT_KEY, '1')
}

/**
 * Conversion for a completed console registration (sign-up + verified email).
 * Queues until the pixel loads if the visitor has not granted optional cookies yet.
 * Attaches hashed email and account id before the event when the account is known.
 */
export function measureOpenAiAdsRegistrationCompleted(
  userId: string,
  identity?: { email?: string | null; phone?: string | null },
) {
  if (!userId) return
  if (!canUseOpenAiAds()) return
  if (hasMeasuredRegistration(userId) || registrationInFlight.has(userId))
    return

  if (!window.oaiq) {
    writeStorage(sessionStorage, PENDING_REGISTRATION_KEY, userId)
    if (identity) hintedIdentity = { externalId: userId, ...identity }
    return
  }

  registrationInFlight.add(userId)
  void deliverOpenAiAdsRegistration(userId, identity)
}

async function deliverOpenAiAdsRegistration(
  userId: string,
  identity?: { email?: string | null; phone?: string | null },
) {
  try {
    if (pixelReady) await pixelReady
    if (!window.oaiq || !canUseOpenAiAds()) {
      writeStorage(sessionStorage, PENDING_REGISTRATION_KEY, userId)
      return
    }
    await attachOpenAiAdsUser({
      externalId: userId,
      email: identity?.email ?? hintedIdentity?.email,
      phone: identity?.phone ?? hintedIdentity?.phone,
    })
    window.oaiq('measure', 'registration_completed', {
      type: 'customer_action',
    })
    markRegistrationMeasured(userId)
  } finally {
    registrationInFlight.delete(userId)
  }
}

function flushPendingOpenAiAdsConversions() {
  const userId = readStorage(sessionStorage, PENDING_REGISTRATION_KEY)
  if (userId) measureOpenAiAdsRegistrationCompleted(userId)
  const organizationId = readStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY)
  if (organizationId) measureOpenAiAdsSubscriptionCreated(organizationId)
  const checkout = readPendingCheckout()
  if (checkout) measureOpenAiAdsCheckoutStarted(checkout.userId, checkout.plan)
}

function hasMeasuredSubscription(organizationId: string): boolean {
  return (
    readStorage(
      localStorage,
      COMPLETED_SUBSCRIPTION_PREFIX + organizationId,
    ) === '1'
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
  if (
    hasMeasuredSubscription(organizationId) ||
    subscriptionInFlight.has(organizationId)
  ) {
    return
  }

  if (!window.oaiq) {
    writeStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY, organizationId)
    return
  }

  subscriptionInFlight.add(organizationId)
  void deliverOpenAiAdsSubscription(organizationId)
}

async function deliverOpenAiAdsSubscription(organizationId: string) {
  try {
    if (pixelReady) await pixelReady
    if (!window.oaiq || !canUseOpenAiAds()) {
      writeStorage(sessionStorage, PENDING_SUBSCRIPTION_KEY, organizationId)
      return
    }
    await attachOpenAiAdsUser(hintedIdentity)
    window.oaiq('measure', 'subscription_created', { type: 'plan_enrollment' })
    markSubscriptionMeasured(organizationId)
  } finally {
    subscriptionInFlight.delete(organizationId)
  }
}

type OpenAiAdsOnboardingStage = {
  status?: string
  at?: string
  actorType?: string
}

/**
 * Product area an onboarding stage counts toward. Installing the CLI or the
 * MCP server is an area of its own, and dedicated databases count as
 * databases: accounts that reach them go Pro as often as the other areas.
 */
function getOpenAiAdsLeadArea(stage: string): string | undefined {
  if (stage === 'cli.install') return 'cli'
  if (stage === 'mcp.install') return 'mcp'
  if (stage.startsWith('project.create') && stage.endsWith('Platform')) {
    return 'platform'
  }
  if (
    stage === 'project.createKey' ||
    stage === 'organization.createProjectKey'
  ) {
    return 'key'
  }
  if (
    stage.startsWith('tablesDB.') ||
    stage.startsWith('documentsDB.') ||
    stage.startsWith('databases.') ||
    stage === 'mysql.create' ||
    stage === 'postgresql.create' ||
    stage === 'mongo.create'
  ) {
    return 'database'
  }
  if (
    stage === 'users.create' ||
    stage === 'account.create' ||
    stage === 'account.createAnonymousSession' ||
    stage === 'teams.create'
  ) {
    return 'auth'
  }
  for (const area of ['storage', 'functions', 'sites', 'messaging']) {
    if (stage.startsWith(`${area}.`)) return area
  }
  return undefined
}

/**
 * When a stage was completed, in milliseconds. The server stamps stages in UTC
 * without a zone (`2026-11-01 12:00:00.000`), which `Date.parse` would read as
 * local time and shift by the browser's offset.
 */
function parseOpenAiAdsStageTime(at: string | undefined): number {
  if (!at) return Number.NaN
  return Date.parse(at.includes('T') ? at : `${at.replace(' ', 'T')}Z`)
}

/**
 * True when the projects completed stages in three or more product areas since
 * the account registered. Stages from before registration (a project shared
 * with an invited member) and backfilled stages (`hidden` actor) don't count.
 */
export function isOpenAiAdsLead(
  projects: ReadonlyArray<{ onboarding?: object }>,
  registration: string,
): boolean {
  const since = Date.parse(registration)
  if (Number.isNaN(since)) return false

  const areas = new Set<string>()
  for (const project of projects) {
    const stages = (project.onboarding ?? {}) as Record<
      string,
      OpenAiAdsOnboardingStage | undefined
    >
    for (const [stage, progress] of Object.entries(stages)) {
      if (progress?.status !== 'completed') continue
      if (progress.actorType === 'hidden') continue
      if (!(parseOpenAiAdsStageTime(progress.at) >= since)) continue
      const area = getOpenAiAdsLeadArea(stage)
      if (area) areas.add(area)
    }
  }
  return areas.size >= LEAD_MIN_AREAS
}

/**
 * Whether to look for the `lead_created` signal now: the pixel is loaded, the
 * account is in its first week and has not converted on this device, and the
 * last look was long enough ago. A true result starts a new interval.
 */
export function shouldCheckOpenAiAdsLead(
  account:
    | { $id: string; registration: string; impersonatorUserId?: string | null }
    | null
    | undefined,
): boolean {
  if (!account?.$id) return false
  if (!canUseOpenAiAds() || !window.oaiq) return false
  if (isConsoleImpersonationActive(account)) return false
  if (
    readStorage(localStorage, COMPLETED_LEAD_PREFIX + account.$id) === '1' ||
    leadInFlight.has(account.$id)
  ) {
    return false
  }
  const now = Date.now()
  if (!(now - Date.parse(account.registration) <= LEAD_WINDOW_MS)) return false
  if (now - leadCheckedAt < LEAD_CHECK_INTERVAL_MS) return false
  leadCheckedAt = now
  return true
}

/**
 * Conversion for an activated builder: a new account that used three or more
 * product areas in its first week. Those accounts go Pro about 5x as often as
 * an average sign-up, so this is the event ads campaigns optimize for.
 */
export function measureOpenAiAdsLeadCreated(userId: string) {
  if (!userId) return
  if (!canUseOpenAiAds() || !window.oaiq) return
  if (
    readStorage(localStorage, COMPLETED_LEAD_PREFIX + userId) === '1' ||
    leadInFlight.has(userId)
  ) {
    return
  }

  leadInFlight.add(userId)
  void deliverOpenAiAdsLead(userId)
}

async function deliverOpenAiAdsLead(userId: string) {
  try {
    if (pixelReady) await pixelReady
    if (!window.oaiq || !canUseOpenAiAds()) return
    await attachOpenAiAdsUser(hintedIdentity)
    window.oaiq(
      'measure',
      'lead_created',
      { type: 'customer_action' },
      { event_id: `lead_${userId}` },
    )
    writeStorage(localStorage, COMPLETED_LEAD_PREFIX + userId, '1')
  } finally {
    leadInFlight.delete(userId)
  }
}

export type OpenAiAdsCheckoutPlan = {
  /** Billing plan id, such as `tier-1`. */
  id: string
  /** Monthly plan price in USD. */
  price?: number
}

/**
 * Conversion for starting a paid checkout: adding a card or submitting a paid
 * plan in the change-plan wizard. Fires once per account. Queues until the
 * pixel loads if the visitor has not granted optional cookies yet.
 */
export function measureOpenAiAdsCheckoutStarted(
  userId: string,
  plan: OpenAiAdsCheckoutPlan,
) {
  if (!userId) return
  if (!canUseOpenAiAds()) return
  if (isConsoleImpersonationActive()) return
  if (
    readStorage(localStorage, COMPLETED_CHECKOUT_PREFIX + userId) === '1' ||
    checkoutInFlight.has(userId)
  ) {
    return
  }

  if (!window.oaiq) {
    writePendingCheckout(userId, plan)
    return
  }

  checkoutInFlight.add(userId)
  void deliverOpenAiAdsCheckout(userId, plan)
}

async function deliverOpenAiAdsCheckout(
  userId: string,
  plan: OpenAiAdsCheckoutPlan,
) {
  try {
    if (pixelReady) await pixelReady
    if (!window.oaiq || !canUseOpenAiAds()) {
      writePendingCheckout(userId, plan)
      return
    }
    await attachOpenAiAdsUser(hintedIdentity)
    const amount = plan.price ? Math.round(plan.price * 100) : 0
    window.oaiq(
      'measure',
      'checkout_started',
      {
        type: 'contents',
        ...(amount > 0 ? { amount, currency: 'USD' } : {}),
        contents: [{ id: plan.id, content_type: 'plan', quantity: 1 }],
      },
      { event_id: `checkout_${userId}` },
    )
    writeStorage(localStorage, COMPLETED_CHECKOUT_PREFIX + userId, '1')
    removeStorage(sessionStorage, PENDING_CHECKOUT_KEY)
  } finally {
    checkoutInFlight.delete(userId)
  }
}

function writePendingCheckout(userId: string, plan: OpenAiAdsCheckoutPlan) {
  writeStorage(
    sessionStorage,
    PENDING_CHECKOUT_KEY,
    JSON.stringify({ userId, plan }),
  )
}

function readPendingCheckout():
  | { userId: string; plan: OpenAiAdsCheckoutPlan }
  | undefined {
  const value = readStorage(sessionStorage, PENDING_CHECKOUT_KEY)
  if (!value) return undefined
  try {
    const pending = JSON.parse(value) as {
      userId?: unknown
      plan?: { id?: unknown; price?: unknown }
    }
    if (typeof pending.userId !== 'string') return undefined
    if (typeof pending.plan?.id !== 'string') return undefined
    const price =
      typeof pending.plan.price === 'number' ? pending.plan.price : undefined
    return { userId: pending.userId, plan: { id: pending.plan.id, price } }
  } catch {
    return undefined
  }
}

/**
 * OAuth sign-up returns to the console with a verified email. Fire only when
 * this tab started on the sign-up page and verification is already satisfied.
 */
export function maybeMeasureOpenAiAdsRegistrationAfterAuth(
  account:
    | {
        $id: string
        email?: string | null
        phone?: string | null
        emailVerification: boolean
        impersonatorUserId?: string | null
      }
    | null
    | undefined,
) {
  if (!canUseOpenAiAds()) return
  if (!account) return
  if (isConsoleImpersonationActive(account)) return
  if (!account.emailVerification) return
  if (readStorage(sessionStorage, REGISTRATION_INTENT_KEY) !== '1') return
  syncOpenAiAdsUser(account)
  measureOpenAiAdsRegistrationCompleted(account.$id, {
    email: account.email,
    phone: account.phone,
  })
}

async function attachOpenAiAdsUser(hint?: OpenAiAdsIdentity) {
  if (!window.oaiq || !canUseOpenAiAds()) return
  const user = await resolveOpenAiAdsUserData(hint)
  if (!user) return
  const key = JSON.stringify(user)
  if (key === attachedUserKey) return
  attachedUserKey = key
  window.oaiq('init', {
    pixelId: OPENAI_ADS_PIXEL_ID,
    user,
  })
}

async function resolveOpenAiAdsUserData(
  hint?: OpenAiAdsIdentity,
): Promise<OpenAiAdsUserData | undefined> {
  const identity = await resolveOpenAiAdsIdentity(hint)
  if (!identity) return undefined
  try {
    return await buildOpenAiAdsUserData(identity)
  } catch {
    return undefined
  }
}

async function resolveOpenAiAdsIdentity(
  hint?: OpenAiAdsIdentity,
): Promise<OpenAiAdsIdentity | undefined> {
  if (isConsoleImpersonationActive()) return undefined

  const cached = getConsoleAccountSync(getConsoleAccountQueryRevision())
  if (cached && isConsoleImpersonationActive(cached)) return undefined

  const externalId = (hint?.externalId || cached?.$id || '').trim()
  const email = hint?.email || cached?.email
  const phone = hint?.phone || cached?.phone
  if (externalId && email) {
    return { externalId, email, phone }
  }

  try {
    const account = await fetchConsoleAccount()
    if (isConsoleImpersonationActive(account)) return undefined
    if (!account.$id)
      return externalId ? { externalId, email, phone } : undefined
    return {
      externalId: externalId || account.$id,
      email: email || account.email,
      phone: phone || account.phone,
    }
  } catch {
    if (!externalId) return undefined
    return { externalId, email, phone }
  }
}

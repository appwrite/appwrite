/**
 * Shared helpers for the project Activity view and detail drawer.
 */

import type { Models } from '@appwrite.io/console'
import { activityResourceTypeLabel } from '@/lib/table-filters'
import {
  getCountryDisplayName,
  normalizeCountryCode,
  type CountryLookups,
} from '@/lib/locale/country-lookups'

/** Older audit rows may still expose split country fields. */
type ActivityEventCountryFields = Models.ActivityEvent & {
  countryCode?: string
  countryName?: string
}

/** Pretty-printed full audit row (same shape as the API / drawer raw JSON). */
export function formatActivityEventJson(event: Models.ActivityEvent): string {
  return JSON.stringify(event, null, 2)
}

/** ISO-3166-1 alpha-2 code from `country` (current API) or legacy `countryCode`. */
export function getActivityCountryCode(
  event: Models.ActivityEvent,
): string | null {
  const legacyCode = normalizeCountryCode(
    (event as ActivityEventCountryFields).countryCode,
  )
  if (legacyCode) return legacyCode.toLowerCase()

  const fromCountry = normalizeCountryCode(event.country)
  return fromCountry ? fromCountry.toLowerCase() : null
}

/** Human-readable country label for table cells and the activity drawer. */
export function getActivityCountryDisplayName(
  event: Models.ActivityEvent,
  lookups?: CountryLookups | null,
): string | null {
  const legacyName = (event as ActivityEventCountryFields).countryName?.trim()
  if (legacyName) return legacyName

  const code = getActivityCountryCode(event)
  if (code) {
    return getCountryDisplayName(code, lookups) ?? code.toUpperCase()
  }

  const country = event.country?.trim()
  if (country) {
    return getCountryDisplayName(country, lookups)
  }

  return null
}

export function isRegularUserType(actorType: string | undefined | null): boolean {
  const normalized = (actorType ?? '').toLowerCase()
  /** `user` is the supported actor; `users` may appear on older audit rows. */
  return normalized === 'user' || normalized === 'users'
}

/**
 * Whether this audit event was triggered by the Appwrite MCP server.
 * The MCP server sets `x-sdk-name: mcp` (stored lowercased in `sdk`) and a
 * `AppwriteMCP/...` user agent. Prefer `sdk`; fall back to user agent for
 * older rows or clients that only set UA.
 */
export function isMcpSdkActivity(
  event:
    | Pick<Models.ActivityEvent, 'sdk' | 'userAgent'>
    | null
    | undefined,
): boolean {
  if (!event) return false
  if (event.sdk?.trim().toLowerCase() === 'mcp') return true
  return /^AppwriteMCP(?:\/|\b)/i.test(event.userAgent?.trim() ?? '')
}

/**
 * Whether this audit event was triggered by the Appwrite CLI.
 * The CLI sets `x-sdk-name: cli` or `Command Line` (stored lowercased in
 * `sdk`) and an `AppwriteCLI/...` user agent. Prefer `sdk`; fall back to
 * user agent for older rows or clients that only set UA.
 */
export function isCliSdkActivity(
  event:
    | Pick<Models.ActivityEvent, 'sdk' | 'userAgent'>
    | null
    | undefined,
): boolean {
  if (!event) return false
  const sdk = event.sdk?.trim().toLowerCase() ?? ''
  if (sdk === 'cli' || sdk === 'command line') return true
  const userAgent = event.userAgent?.trim() ?? ''
  return (
    userAgent.startsWith('AppwriteCLI') || userAgent.startsWith('appwritecli')
  )
}

/**
 * Whether this actor has a real human email worth surfacing as the secondary
 * line under their name. End-users do (their auth email); admins do (their
 * console account email). API keys and system actors don't - their `actorEmail`
 * is often a synthetic service address, so we fall back to the actor id for those.
 */
export function hasHumanEmail(actorType: string | undefined | null): boolean {
  const normalized = (actorType ?? '').toLowerCase()
  return isRegularUserType(actorType) || normalized === 'admin'
}

/**
 * Label + color used in the dedicated "Type" column so each row's actor type
 * (end-user, admin, API key, system) is identifiable at a glance.
 */
export function userTypeBadge(
  actorType: string | undefined | null,
): { label: string; tone: string } {
  const normalized = (actorType ?? '').toLowerCase()

  if (isRegularUserType(actorType)) {
    return {
      label: 'User',
      tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    }
  }
  if (normalized === 'admin') {
    return {
      label: 'Admin',
      tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    }
  }
  if (normalized === 'guest') {
    return {
      label: 'Guest',
      tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    }
  }
  if (normalized === 'keyproject') {
    return {
      label: 'Project key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyaccount') {
    return {
      label: 'Account key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized === 'keyorganization') {
    return {
      label: 'Org key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  if (normalized.startsWith('key')) {
    return {
      label: 'API key',
      tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    }
  }
  return {
    label: 'System',
    tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  }
}

export type ActivityResourceVisualIcon =
  | 'document'
  | 'collection'
  | 'row'
  | 'table'
  | 'database'
  | 'file'
  | 'bucket'
  | 'function'
  | 'user'
  | 'team'
  | 'site'
  | 'rule'
  | 'identity'
  | 'message'
  | 'topic'
  | 'provider'
  | 'subscriber'
  | 'target'
  | 'token'
  | 'webhook'
  | 'schedule'
  | 'migrations'
  | 'report'
  | 'vectorsdb'
  | 'project'
  | 'presence'
  | 'platform'
  | 'variable'
  | 'labels'
  | 'phone'
  | 'app'
  | 'archive'
  | 'policy'
  | 'restoration'
  | 'affiliate'
  | 'billing'
  | 'payment'
  | 'installation'
  | 'membership'
  | 'threat'
  | 'cache'

const RESOURCE_TYPE_VISUALS: Record<
  ActivityResourceVisualIcon,
  { tone: string }
> = {
  document: { tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
  collection: { tone: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' },
  row: { tone: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400' },
  table: { tone: 'bg-teal-500/10 text-teal-600 dark:text-teal-400' },
  database: { tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
  file: { tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
  bucket: { tone: 'bg-orange-500/10 text-orange-600 dark:text-orange-400' },
  function: { tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
  user: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  team: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  site: { tone: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400' },
  rule: { tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
  identity: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  message: { tone: 'bg-pink-500/10 text-pink-600 dark:text-pink-400' },
  topic: { tone: 'bg-pink-500/10 text-pink-600 dark:text-pink-400' },
  provider: { tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' },
  subscriber: { tone: 'bg-pink-500/10 text-pink-600 dark:text-pink-400' },
  target: { tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' },
  token: { tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
  webhook: { tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
  schedule: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
  migrations: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
  report: { tone: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' },
  vectorsdb: { tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
  project: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
  presence: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  platform: { tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
  variable: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
  labels: { tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
  phone: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  app: { tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
  archive: { tone: 'bg-orange-500/10 text-orange-600 dark:text-orange-400' },
  policy: { tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
  restoration: { tone: 'bg-teal-500/10 text-teal-600 dark:text-teal-400' },
  affiliate: { tone: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400' },
  billing: { tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
  payment: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  installation: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
  membership: { tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
  threat: { tone: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' },
  cache: { tone: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
}

/** Audit `resourceType` token → icon. Keys are lowercase. */
const RESOURCE_TYPE_ICONS: Record<string, ActivityResourceVisualIcon> = {
  document: 'document',
  collection: 'collection',
  row: 'row',
  table: 'table',
  database: 'database',
  dedicateddatabase: 'database',
  dedicateddatabasebackup: 'archive',
  dedicateddatabaserestoration: 'restoration',
  embeddings: 'vectorsdb',
  vectorsdb: 'vectorsdb',
  file: 'file',
  bucket: 'bucket',
  function: 'function',
  site: 'site',
  rule: 'rule',
  schedule: 'schedule',
  user: 'user',
  team: 'team',
  identity: 'identity',
  membership: 'membership',
  presence: 'presence',
  target: 'target',
  token: 'token',
  tokens: 'token',
  message: 'message',
  topic: 'topic',
  provider: 'provider',
  subscriber: 'subscriber',
  project: 'project',
  'project.key': 'token',
  'project.platform': 'platform',
  'project.oauth2': 'identity',
  'project.variable': 'variable',
  'project.authmethods': 'identity',
  'project.labels': 'labels',
  'project.mock-phone': 'phone',
  'project.protocols': 'rule',
  'project.services': 'project',
  'project.smtp': 'message',
  'project.template': 'document',
  webhook: 'webhook',
  migrations: 'migrations',
  report: 'report',
  app: 'app',
  analyticsproperty: 'report',
  archive: 'archive',
  policy: 'policy',
  restoration: 'restoration',
  installation: 'installation',
  'organization.key': 'token',
  affiliatelink: 'affiliate',
  affiliatereward: 'affiliate',
  billingaddress: 'billing',
  paymentmethod: 'payment',
  threat: 'threat',
  cache: 'cache',
}

/**
 * Icon + tone for an audit `resourceType` token (table, row, function, …).
 * Label comes from {@link activityResourceTypeLabel}.
 */
export function resourceTypeVisual(
  resourceType: string | undefined | null,
): {
  label: string
  tone: string
  icon: ActivityResourceVisualIcon
} {
  const normalized = resourceType?.trim().toLowerCase() ?? ''
  const icon = RESOURCE_TYPE_ICONS[normalized] ?? 'project'
  return {
    label: activityResourceTypeLabel(resourceType ?? '') || 'Project',
    tone: RESOURCE_TYPE_VISUALS[icon].tone,
    icon,
  }
}

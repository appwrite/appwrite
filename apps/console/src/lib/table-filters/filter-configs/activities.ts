/**
 * Filter columns for project activity (audit log) list.
 *
 * Query attributes must match `ActivityEvents::ALLOWED_ATTRIBUTES` in
 * appwrite-labs/cloud (`listEvents` / `GET /v1/activities/events`).
 * Response fields such as `ip`, `hostname`, `sdk`, `actorEmail`, and `$id`
 * are returned on the event but are not queryable. `sdk` is a Console-only
 * filter (Via MCP / Via CLI) applied to the loaded page.
 */

import { buildFilterQueryString } from '../operators'
import type { CompactFilterKey, FilterColumn, FilterMap } from '../types'
import { findCompactFilterKeyInMap } from '../url'

/**
 * `resourceType` is the second-to-last segment of the audit `resource` path
 * (ClickHouse `parseResource`). Values are path tokens from
 * `audits.resource` in appwrite/appwrite and appwrite-labs/cloud.
 */
const ACTIVITY_RESOURCE_TYPE_ELEMENTS = [
  { value: 'document', label: 'Document' },
  { value: 'collection', label: 'Collection' },
  { value: 'row', label: 'Row' },
  { value: 'table', label: 'Table' },
  { value: 'database', label: 'Database' },
  { value: 'dedicatedDatabase', label: 'Dedicated database' },
  { value: 'dedicatedDatabaseBackup', label: 'Dedicated backup' },
  { value: 'dedicatedDatabaseRestoration', label: 'Dedicated restoration' },
  { value: 'embeddings', label: 'Embeddings' },
  { value: 'vectorsdb', label: 'VectorsDB' },
  { value: 'file', label: 'File' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'function', label: 'Function' },
  { value: 'site', label: 'Site' },
  { value: 'rule', label: 'Rule' },
  { value: 'schedule', label: 'Schedule' },
  { value: 'user', label: 'User' },
  { value: 'team', label: 'Team' },
  { value: 'identity', label: 'Identity' },
  { value: 'membership', label: 'Membership' },
  { value: 'presence', label: 'Presence' },
  { value: 'target', label: 'Target' },
  { value: 'token', label: 'Token' },
  { value: 'tokens', label: 'Token' },
  { value: 'message', label: 'Message' },
  { value: 'topic', label: 'Topic' },
  { value: 'provider', label: 'Provider' },
  { value: 'subscriber', label: 'Subscriber' },
  { value: 'project', label: 'Project' },
  { value: 'project.key', label: 'API key' },
  { value: 'project.platform', label: 'Platform' },
  { value: 'project.oauth2', label: 'OAuth2' },
  { value: 'project.variable', label: 'Variable' },
  { value: 'project.authMethods', label: 'Auth methods' },
  { value: 'project.labels', label: 'Labels' },
  { value: 'project.mock-phone', label: 'Mock phone' },
  { value: 'project.protocols', label: 'Protocols' },
  { value: 'project.services', label: 'Services' },
  { value: 'project.smtp', label: 'SMTP' },
  { value: 'project.template', label: 'Email template' },
  { value: 'webhook', label: 'Webhook' },
  { value: 'migrations', label: 'Migrations' },
  { value: 'report', label: 'Report' },
  { value: 'app', label: 'App' },
  { value: 'analyticsProperty', label: 'Analytics property' },
  { value: 'archive', label: 'Backup archive' },
  { value: 'policy', label: 'Backup policy' },
  { value: 'restoration', label: 'Restoration' },
  { value: 'installation', label: 'Git installation' },
  { value: 'organization.key', label: 'Organization API key' },
  { value: 'affiliateLink', label: 'Affiliate link' },
  { value: 'affiliateReward', label: 'Affiliate reward' },
  { value: 'billingAddress', label: 'Billing address' },
  { value: 'paymentMethod', label: 'Payment method' },
  { value: 'threat', label: 'Threat' },
  { value: 'cache', label: 'Cache' },
]

/** Title-case an unknown audit token (`project.fooBar` → `Foo Bar`). */
function humanizeActivityResourceType(value: string): string {
  let token = value.trim()
  const dot = token.lastIndexOf('.')
  if (dot >= 0) token = token.slice(dot + 1)

  let spaced = ''
  for (let i = 0; i < token.length; i++) {
    const ch = token[i]!
    if (ch === '-' || ch === '_') {
      spaced += ' '
      continue
    }
    const prev = i > 0 ? token[i - 1]! : ''
    if (
      i > 0 &&
      ch >= 'A' &&
      ch <= 'Z' &&
      prev >= 'a' &&
      prev <= 'z'
    ) {
      spaced += ' '
    }
    spaced += ch
  }

  return spaced
    .split(' ')
    .filter((part) => part.length > 0)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

/** Human label for a stored audit `resourceType` path token. */
export function activityResourceTypeLabel(value: string): string {
  const normalized = value.trim()
  if (!normalized) return ''
  const lower = normalized.toLowerCase()
  return (
    ACTIVITY_RESOURCE_TYPE_ELEMENTS.find(
      (el) => el.value.toLowerCase() === lower,
    )?.label ?? humanizeActivityResourceType(normalized)
  )
}

/**
 * Stored `ActivityEvent.actorType` values from server-ce / cloud constants:
 * `user`, `admin`, `guest`, `hidden`, `keyProject`, `keyAccount`,
 * `keyOrganization`, `appInstallation`.
 */
const ACTIVITY_ACTOR_TYPE_ELEMENTS = [
  { value: 'user', label: 'User (client API)' },
  { value: 'admin', label: 'Admin' },
  { value: 'guest', label: 'Guest' },
  { value: 'hidden', label: 'Hidden' },
  { value: 'keyProject', label: 'Project API key' },
  { value: 'keyAccount', label: 'Account API key' },
  { value: 'keyOrganization', label: 'Partners API key' },
  { value: 'appInstallation', label: 'App installation' },
]

/** Console-only. Not in ActivityEvents::ALLOWED_ATTRIBUTES. */
export const ACTIVITY_SDK_FILTER_COLUMN_ID = 'sdk'

export const ACTIVITY_SDK_SOURCE_MCP = 'mcp'
export const ACTIVITY_SDK_SOURCE_CLI = 'cli'

export type ActivitySdkSource =
  | typeof ACTIVITY_SDK_SOURCE_MCP
  | typeof ACTIVITY_SDK_SOURCE_CLI

const ACTIVITY_SDK_SOURCE_ELEMENTS: Array<{
  value: ActivitySdkSource
  label: string
}> = [
  { value: ACTIVITY_SDK_SOURCE_MCP, label: 'Via MCP' },
  { value: ACTIVITY_SDK_SOURCE_CLI, label: 'Via CLI' },
]

function isActivitySdkSource(value: string): value is ActivitySdkSource {
  return (
    value === ACTIVITY_SDK_SOURCE_MCP || value === ACTIVITY_SDK_SOURCE_CLI
  )
}

/** Active Via MCP / Via CLI equal filters from the URL map. */
export function getSelectedActivitySdkSources(
  filterMap: FilterMap,
): ActivitySdkSource[] {
  const selected = new Set<ActivitySdkSource>()
  for (const [key] of filterMap) {
    if (key.c !== ACTIVITY_SDK_FILTER_COLUMN_ID) continue
    if (key.o !== 'equal' && key.o !== 'is') continue
    const raw = key.v == null ? [] : Array.isArray(key.v) ? key.v : [key.v]
    for (const item of raw) {
      const normalized = String(item).trim().toLowerCase()
      if (isActivitySdkSource(normalized)) selected.add(normalized)
    }
  }
  return ACTIVITY_SDK_SOURCE_ELEMENTS.map((el) => el.value).filter((value) =>
    selected.has(value),
  )
}

/** Add or remove one SDK source equal filter without clearing the other. */
export function toggleActivitySdkSourceInMap(
  filterMap: FilterMap,
  source: ActivitySdkSource,
): FilterMap {
  const next = new Map(filterMap)
  const existing =
    findCompactFilterKeyInMap(next, {
      c: ACTIVITY_SDK_FILTER_COLUMN_ID,
      o: 'equal',
      v: source,
    }) ??
    findCompactFilterKeyInMap(next, {
      c: ACTIVITY_SDK_FILTER_COLUMN_ID,
      o: 'is',
      v: source,
    })
  if (existing) {
    next.delete(existing)
    return next
  }
  const compactKey: CompactFilterKey = {
    c: ACTIVITY_SDK_FILTER_COLUMN_ID,
    o: 'equal',
    v: source,
  }
  next.set(
    compactKey,
    buildFilterQueryString('equal', compactKey.c, compactKey.v),
  )
  return next
}

const ACTIVITY_CORE_FILTER_COLUMNS: FilterColumn[] = [
  { id: 'time', title: 'Time', type: 'datetime' },
  {
    id: ACTIVITY_SDK_FILTER_COLUMN_ID,
    title: 'Source',
    type: 'enum',
    format: 'enum',
    elements: ACTIVITY_SDK_SOURCE_ELEMENTS,
    optional: false,
  },
  {
    id: 'resourceType',
    title: 'Resource type',
    type: 'enum',
    format: 'enum',
    elements: ACTIVITY_RESOURCE_TYPE_ELEMENTS,
    optional: false,
  },
  { id: 'resourceId', title: 'Resource ID', type: 'string' },
  { id: 'resource', title: 'Resource path', type: 'string' },
  { id: 'resourceParent', title: 'Resource parent', type: 'string' },
  {
    id: 'actorType',
    title: 'Actor type',
    type: 'enum',
    format: 'enum',
    elements: ACTIVITY_ACTOR_TYPE_ELEMENTS,
    optional: false,
  },
  { id: 'actorId', title: 'Actor ID', type: 'string' },
  { id: 'event', title: 'Event path', type: 'string' },
]

/**
 * Activity list filters. Country options come from `sdk.forConsole.locale.listCountries()`
 * (see `useCountries` / `countriesQueryOptions`). Filter attribute is `country`
 * (ISO-3166-1 alpha-2 code); labels use the human-readable country name.
 *
 * `teamId` is allowed by the API but omitted here: this list is already
 * project-scoped, so every row shares the project's team.
 */
export function getActivitiesFilterColumns(
  countryElements: Array<{ value: string; label: string }>,
): FilterColumn[] {
  return [
    ...ACTIVITY_CORE_FILTER_COLUMNS,
    {
      id: 'country',
      title: 'Country',
      type: 'enum',
      format: 'enum',
      elements: countryElements,
      optional: false,
    },
  ]
}

/**
 * Activity filters without locale-backed country options (empty enum → text input for country).
 * Prefer {@link getActivitiesFilterColumns} with `useCountries()` in the Activity view.
 * Kept for backward compatibility with imports of this name from `@/lib/table-filters`.
 */
export const activitiesFilterColumns: FilterColumn[] =
  getActivitiesFilterColumns([])

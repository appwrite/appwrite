/**
 * Filter columns for project activity (audit log) list.
 *
 * Query attributes must match `ActivityEvents::ALLOWED_ATTRIBUTES` in
 * appwrite-labs/cloud (`listEvents` / `GET /v1/activities/events`).
 * Response fields such as `ip`, `hostname`, `sdk`, `actorEmail`, and `$id`
 * are returned on the event but are not queryable.
 */

import type { FilterColumn } from '../types'

/**
 * `resourceType` is the second-to-last segment of the audit `resource` path
 * (ClickHouse `parseResource`). Values are path tokens, not UI buckets.
 */
const ACTIVITY_RESOURCE_TYPE_ELEMENTS = [
  { value: 'document', label: 'Document' },
  { value: 'collection', label: 'Collection' },
  { value: 'row', label: 'Row' },
  { value: 'table', label: 'Table' },
  { value: 'database', label: 'Database' },
  { value: 'file', label: 'File' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'function', label: 'Function' },
  { value: 'user', label: 'User / key' },
  { value: 'team', label: 'Team' },
  { value: 'site', label: 'Site' },
  { value: 'rule', label: 'Rule' },
  { value: 'identity', label: 'Identity' },
  { value: 'message', label: 'Message' },
  { value: 'topic', label: 'Topic' },
  { value: 'provider', label: 'Provider' },
  { value: 'subscriber', label: 'Subscriber' },
  { value: 'target', label: 'Target' },
  { value: 'token', label: 'Token' },
  { value: 'webhook', label: 'Webhook' },
  { value: 'schedule', label: 'Schedule' },
  { value: 'migrations', label: 'Migrations' },
  { value: 'report', label: 'Report' },
  { value: 'vectorsdb', label: 'VectorsDB' },
  { value: 'project', label: 'Project' },
]

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

const ACTIVITY_CORE_FILTER_COLUMNS: FilterColumn[] = [
  { id: 'time', title: 'Time', type: 'datetime' },
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

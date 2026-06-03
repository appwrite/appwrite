/**
 * Filter columns for project activity (audit log) list.
 */

import type { FilterColumn } from '../types'

const ACTIVITY_RESOURCE_TYPE_ELEMENTS = [
  { value: 'document', label: 'Document' },
  { value: 'collection', label: 'Collection' },
  { value: 'database', label: 'Database' },
  { value: 'file', label: 'File' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'function', label: 'Function' },
  { value: 'user', label: 'User / key' },
  { value: 'team', label: 'Team' },
  { value: 'site', label: 'Site' },
  { value: 'rule', label: 'Rule' },
  { value: 'project', label: 'Project' },
]

/**
 * Supported `ActivityEvent.actorType` values (Appwrite activity audit actors).
 */
const ACTIVITY_ACTOR_TYPE_ELEMENTS = [
  { value: 'user', label: 'User (client API)' },
  { value: 'admin', label: 'Admin' },
  { value: 'guest', label: 'Guest' },
  { value: 'keyProject', label: 'Project API key' },
  { value: 'keyAccount', label: 'Account API key' },
  { value: 'keyOrganization', label: 'Organization API key' },
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
    { id: 'ip', title: 'IP address', type: 'string' },
  ]
}

/**
 * Activity filters without locale-backed country options (empty enum → text input for country).
 * Prefer {@link getActivitiesFilterColumns} with `useCountries()` in the Activity view.
 * Kept for backward compatibility with imports of this name from `@/lib/table-filters`.
 */
export const activitiesFilterColumns: FilterColumn[] =
  getActivitiesFilterColumns([])

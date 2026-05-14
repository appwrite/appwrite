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
  { value: 'project', label: 'Project' },
]

export const activitiesFilterColumns: FilterColumn[] = [
  { id: 'time', title: 'Time', type: 'datetime' },
  {
    id: 'resourceType',
    title: 'Resource type',
    type: 'enum',
    format: 'enum',
    elements: ACTIVITY_RESOURCE_TYPE_ELEMENTS,
    optional: false,
  },
  { id: 'userId', title: 'User ID', type: 'string' },
  { id: 'event', title: 'Event path', type: 'string' },
]

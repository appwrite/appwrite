/**
 * Predefined filter columns for function executions / site logs list.
 */

import type { FilterColumn } from '../types'

const EXECUTION_STATUS_ELEMENTS = [
  { value: 'completed', label: 'Completed' },
  { value: 'processing', label: 'Processing' },
  { value: 'failed', label: 'Failed' },
  { value: 'waiting', label: 'Waiting' },
]

export const executionsFilterColumns: FilterColumn[] = [
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: EXECUTION_STATUS_ELEMENTS,
    optional: false,
  },
  { id: '$createdAt', title: 'Created', type: 'datetime' },
]

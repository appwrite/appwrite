/**
 * Predefined filter columns for site/function deployments list.
 */

import type { FilterColumn } from '../types'

const DEPLOYMENT_STATUS_ELEMENTS = [
  { value: 'ready', label: 'Ready' },
  { value: 'building', label: 'Building' },
  { value: 'processing', label: 'Processing' },
  { value: 'waiting', label: 'Waiting' },
  { value: 'failed', label: 'Failed' },
  { value: 'timeout', label: 'Timeout' },
]

export const deploymentsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: DEPLOYMENT_STATUS_ELEMENTS,
    optional: false,
  },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]

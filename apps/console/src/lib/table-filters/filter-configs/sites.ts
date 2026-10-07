/**
 * Predefined filter columns for project sites list.
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

export const sitesFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  {
    id: 'latestDeploymentStatus',
    title: 'Deployment status',
    type: 'enum',
    format: 'enum',
    elements: DEPLOYMENT_STATUS_ELEMENTS,
    optional: false,
  },
  {
    id: 'enabled',
    title: 'Enabled',
    type: 'boolean',
  },
  {
    id: 'live',
    title: 'Live',
    type: 'boolean',
  },
  { id: 'deploymentCreatedAt', title: 'Last deployed', type: 'datetime' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]

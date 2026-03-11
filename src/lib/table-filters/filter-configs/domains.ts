/**
 * Predefined filter columns for organization domains list.
 */

import type { FilterColumn } from '../types'

export const domainsFilterColumns: FilterColumn[] = [
  { id: 'domain', title: 'Domain', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]

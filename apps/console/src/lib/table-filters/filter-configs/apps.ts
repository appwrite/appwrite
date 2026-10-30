/**
 * Predefined filter columns for organization / partner OAuth2 apps lists.
 */

import type { FilterColumn } from '../types'

export const appsFilterColumns: FilterColumn[] = [
  { id: 'name', title: 'Name', type: 'string' },
  { id: '$id', title: '$id', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]

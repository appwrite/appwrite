/**
 * Predefined filter columns for project teams list (Auth).
 * Use when building the Filters UI for teams; same contract as schema-driven lists.
 */

import type { FilterColumn } from '../types'

export const teamsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]

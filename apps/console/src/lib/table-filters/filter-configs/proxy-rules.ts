/**
 * Predefined filter columns for proxy rules (site/function domains).
 * No `status` column: the rules list endpoint does not accept status queries.
 */

import type { FilterColumn } from '../types'

export const proxyRulesFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'domain', title: 'Domain', type: 'string' },
  { id: 'type', title: 'Type', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]

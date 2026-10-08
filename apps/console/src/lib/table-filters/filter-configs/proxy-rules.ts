/**
 * Predefined filter columns for proxy rules (site/function domains).
 */

import type { FilterColumn } from '../types'

const PROXY_RULE_STATUS_ELEMENTS = [
  { value: 'verified', label: 'Verified' },
  { value: 'verifying', label: 'Verifying' },
  { value: 'unverified', label: 'Unverified' },
  { value: 'created', label: 'Created' },
]

export const proxyRulesFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'domain', title: 'Domain', type: 'string' },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: PROXY_RULE_STATUS_ELEMENTS,
    optional: false,
  },
  { id: 'type', title: 'Type', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]

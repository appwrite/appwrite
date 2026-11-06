/**
 * Predefined filter columns for DNS records list (domain detail).
 */

import type { FilterColumn } from '../types'

const DNS_RECORD_TYPES = [
  { value: 'A', label: 'A' },
  { value: 'AAAA', label: 'AAAA' },
  { value: 'CNAME', label: 'CNAME' },
  { value: 'MX', label: 'MX' },
  { value: 'TXT', label: 'TXT' },
  { value: 'NS', label: 'NS' },
  { value: 'SRV', label: 'SRV' },
  { value: 'CAA', label: 'CAA' },
  { value: 'HTTPS', label: 'HTTPS' },
  { value: 'ALIAS', label: 'ALIAS' },
]

export const dnsRecordsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'name', title: 'Name', type: 'string' },
  {
    id: 'type',
    title: 'Type',
    type: 'enum',
    format: 'enum',
    elements: DNS_RECORD_TYPES,
    optional: false, // DNS record type is always set
  },
  { id: 'value', title: 'Value', type: 'string' },
  { id: 'ttl', title: 'TTL', type: 'integer' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]

import {
  DATABASE_COMPUTE_CREDITS_NOTE,
  DEFAULT_PITR_RATE,
} from '@/lib/database-create-pricing'
import { NATIVE_DATABASE_COMPUTE_SPEC_OPTIONS } from '@/lib/database-specs'

export const PRICING_DATABASE_ANCHOR_ID = 'database-pricing'

export const DEDICATED_DB_PITR_PERCENT = Math.round(DEFAULT_PITR_RATE * 100)

/** Stable i18n keys for dedicated database add-on pricing (see Postgres docs specifications table). */
export const DEDICATED_DB_HA_REPLICA_PRICING_LABEL = 'Full tier price per replica'
export const DEDICATED_DB_PITR_PRICING_LABEL = `+${DEDICATED_DB_PITR_PERCENT}% of base`
export const DEDICATED_DB_REPLICA_AND_PITR_PRICING_SUMMARY = `Full tier price per replica, +${DEDICATED_DB_PITR_PERCENT}% for point-in-time recovery`

export const DATABASE_PRICING_COMPARISON_ROWS = [
  {
    label: 'Monthly compute',
    serverless: '$0 fixed fee',
    dedicated: 'From $10/mo per database',
  },
  {
    label: 'Reads and writes',
    serverless: 'Plan quota, then overage',
    dedicated: 'Included in compute tier',
  },
  {
    label: 'Storage',
    serverless: 'Project storage rates',
    dedicated: 'Tier allowance + overage',
  },
  {
    label: 'CPU and memory',
    serverless: 'Serverless',
    dedicated: 'Reserved per tier',
  },
  {
    label: 'HA replicas',
    serverless: '-',
    dedicated: DEDICATED_DB_HA_REPLICA_PRICING_LABEL,
  },
  {
    label: 'Point-in-time recovery',
    serverless: '-',
    dedicated: DEDICATED_DB_PITR_PRICING_LABEL,
  },
  {
    label: 'Database types',
    serverless: 'TablesDB (default)',
    dedicated:
      'DocumentsDB, VectorsDB,\nPostgreSQL, MySQL,\nor upgraded TablesDB',
  },
] as const

export const SERVERLESS_DATABASE_PRICING = {
  label: 'Serverless',
  subtitle: 'TablesDB default',
  summary: 'Pay for storage and database operations. No fixed compute fee.',
  bestFor: 'Variable traffic and early-stage apps.',
  billingModel: 'Pay as you go',
  costLines: [
    { label: 'Compute', value: 'No fixed monthly fee' },
    { label: 'Reads and writes', value: 'Plan quota, then overage' },
    { label: 'Storage', value: 'Project storage rates' },
  ],
  highlights: [
    'Serverless CPU, memory, and connections',
    'Default for TablesDB',
  ],
} as const

export const DEDICATED_DATABASE_PRICING = {
  label: 'Dedicated compute',
  subtitle: 'Reserved resources',
  summary: 'Fixed monthly compute tiers with reserved CPU, memory, and connections.',
  bestFor: 'Production workloads and direct SQL clients.',
  billingModel: 'Monthly base tier + add-ons',
  costLines: [
    { label: 'Compute tier', value: 'From $10/mo per database' },
    {
      label: 'HA read replicas',
      value: DEDICATED_DB_HA_REPLICA_PRICING_LABEL,
    },
    {
      label: 'Point-in-time recovery',
      value: DEDICATED_DB_PITR_PRICING_LABEL,
    },
    { label: 'Extra storage and bandwidth', value: 'Usage-based overage' },
  ],
  highlights: [
    'Reserved CPU, RAM, and connection limits',
    'Required for DocumentsDB, VectorsDB, PostgreSQL, and MySQL',
  ],
  computeCreditsNote: DATABASE_COMPUTE_CREDITS_NOTE,
} as const

/** Dedicated compute tiers shown on the pricing page and product marketing tables. */
export const DEDICATED_DATABASE_PRICING_TIERS =
  NATIVE_DATABASE_COMPUTE_SPEC_OPTIONS

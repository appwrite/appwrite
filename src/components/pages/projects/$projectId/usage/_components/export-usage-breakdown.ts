import type { CountryLookups } from '@/lib/locale/country-lookups'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'
import { downloadAsFile } from '@/lib/utils/database-schema-export'
import { formatBreakdownLabel } from './UsageBreakdownRows'

const REQUESTS_COLUMN = 'Requests'
const BANDWIDTH_COLUMN = 'Bandwidth'
const DATABASE_READS_COLUMN = 'Reads'
const DATABASE_WRITES_COLUMN = 'Writes'

const VALUE_COLUMN_BY_KIND = {
  requests: REQUESTS_COLUMN,
  bandwidth: BANDWIDTH_COLUMN,
  'database-reads': DATABASE_READS_COLUMN,
  'database-writes': DATABASE_WRITES_COLUMN,
} as const

export type UsageBreakdownExportKind = keyof typeof VALUE_COLUMN_BY_KIND

const DIMENSION_LABEL_COLUMN: Record<UsageEventBreakdownDimension, string> = {
  path: 'Path',
  method: 'Method',
  status: 'Status',
  service: 'Service',
  country: 'Country',
  city: 'Caller city',
  hostname: 'Hostname',
  ip: 'IP address',
  osName: 'OS',
  clientType: 'Client type',
  clientName: 'Client name',
  deviceName: 'Device',
  teamId: 'Team ID',
  resourceId: 'Resource ID',
  resourceType: 'Resource type',
  resource: 'Resource',
  sdk: 'SDK',
}

function escapeCsvCell(value: string): string {
  if (/[",\n\r]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}

function sanitizeFilename(title: string): string {
  const sanitized = title.replace(/[^a-zA-Z0-9_-]+/g, '_').replace(/^_+|_+$/g, '')
  return sanitized || 'breakdown'
}

function buildExportFilename(title: string, extension: string): string {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)
  return `${sanitizeFilename(title)}_${stamp}.${extension}`
}

type UsageBreakdownExportRow = {
  label: string
  displayLabel: string
  value: number
}

function buildUsageBreakdownExportRows(
  items: UsageBreakdownItem[],
  dimension: UsageEventBreakdownDimension,
  labelVariant: 'mono' | 'default',
  countryLookups: CountryLookups | null,
  databaseLookup?: DatabaseBreakdownResourceMap | null,
  computeLookup?: ComputeBreakdownResourceMap | null,
  storageLookup?: StorageBreakdownResourceMap | null,
  tableLookup?: TableBreakdownResourceMap | null,
): UsageBreakdownExportRow[] {
  return items.map((item) => ({
    label: item.label,
    displayLabel: formatBreakdownLabel(
      item,
      labelVariant,
      dimension,
      countryLookups,
      databaseLookup,
      computeLookup,
      storageLookup,
      tableLookup,
    ),
    value: item.count,
  }))
}

export function downloadUsageBreakdownJson(
  items: UsageBreakdownItem[],
  title: string,
  dimension: UsageEventBreakdownDimension,
  labelVariant: 'mono' | 'default',
  countryLookups: CountryLookups | null,
  kind: UsageBreakdownExportKind = 'requests',
  databaseLookup?: DatabaseBreakdownResourceMap | null,
  computeLookup?: ComputeBreakdownResourceMap | null,
  storageLookup?: StorageBreakdownResourceMap | null,
  tableLookup?: TableBreakdownResourceMap | null,
) {
  const labelColumn = DIMENSION_LABEL_COLUMN[dimension]
  const valueColumn = VALUE_COLUMN_BY_KIND[kind]
  const rows = buildUsageBreakdownExportRows(
    items,
    dimension,
    labelVariant,
    countryLookups,
    databaseLookup,
    computeLookup,
    storageLookup,
    tableLookup,
  )

  const payload = {
    title,
    dimension,
    items: rows.map((row) => ({
      [labelColumn]: row.displayLabel,
      label: row.label,
      [valueColumn]: row.value,
    })),
  }

  downloadAsFile(
    JSON.stringify(payload, null, 2),
    buildExportFilename(title, 'json'),
    'application/json',
  )
}

export function downloadUsageBreakdownCsv(
  items: UsageBreakdownItem[],
  title: string,
  dimension: UsageEventBreakdownDimension,
  labelVariant: 'mono' | 'default',
  countryLookups: CountryLookups | null,
  kind: UsageBreakdownExportKind = 'requests',
  databaseLookup?: DatabaseBreakdownResourceMap | null,
  computeLookup?: ComputeBreakdownResourceMap | null,
  storageLookup?: StorageBreakdownResourceMap | null,
  tableLookup?: TableBreakdownResourceMap | null,
) {
  const labelColumn = DIMENSION_LABEL_COLUMN[dimension]
  const valueColumn = VALUE_COLUMN_BY_KIND[kind]
  const rows = buildUsageBreakdownExportRows(
    items,
    dimension,
    labelVariant,
    countryLookups,
    databaseLookup,
    computeLookup,
    storageLookup,
    tableLookup,
  )

  const header = [labelColumn, valueColumn].map(escapeCsvCell).join(',')
  const lines = rows.map((row) =>
    [escapeCsvCell(row.displayLabel), String(row.value)].join(','),
  )

  downloadAsFile(
    [header, ...lines].join('\n'),
    buildExportFilename(title, 'csv'),
    'text/csv',
  )
}

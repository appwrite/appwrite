import type { Translator } from '@/lib/i18n/translate'
import { getPostgresIndexAlgorithmLabel } from '@/lib/postgres-index-metadata'
import { getMysqlIndexAlgorithmLabel } from '@/lib/mysql-index-metadata'
import {
  formatConnectionStateLabel,
  formatPostgresBackendTypeLabel,
  formatPostgresConnectionStateLabel,
} from '@/lib/postgres-metrics'
import {
  formatMysqlBackendTypeLabel,
  formatMysqlConnectionStateLabel,
} from '@/lib/mysql-metrics'

const RESOURCE_STATUS_LABEL_KEYS: Record<string, string> = {
  available: 'Available',
  processing: 'Processing',
  deleting: 'Deleting',
  stuck: 'Stuck',
  failed: 'Failed',
  ready: 'Ready',
  provisioning: 'Provisioning',
  restoring: 'Restoring',
  scaling: 'Scaling',
  upgrading: 'Upgrading',
  migrating: 'Migrating',
  pausing: 'Pausing',
  resuming: 'Resuming',
  inactive: 'Inactive',
  paused: 'Paused',
  deleted: 'Deleted',
  active: 'Active',
  idle: 'Idle',
  disabled: 'Disabled',
  enabled: 'Enabled',
  scheduled: 'Scheduled',
  pending: 'Pending',
  completed: 'Completed',
  complete: 'Complete',
  uploading: 'Uploading',
  downloading: 'Downloading',
  draft: 'Draft',
  sent: 'Sent',
  delivered: 'Delivered',
  waiting: 'Waiting',
}

/**
 * Localize API resource lifecycle statuses shown in badges (columns, indexes, dedicated DBs, etc.).
 */
export function localizeResourceStatusLabel(
  status: string | null | undefined,
  t: Translator,
): string {
  if (!status?.trim()) return t('Unknown')
  const trimmed = status.trim()
  const mapped =
    RESOURCE_STATUS_LABEL_KEYS[trimmed.toLowerCase()] ??
    trimmed.charAt(0).toUpperCase() + trimmed.slice(1)
  return t(mapped)
}

export function localizeTableIndexTypeLabel(type: string, t: Translator): string {
  switch (type.toLowerCase()) {
    case 'key':
      return t('Key')
    case 'unique':
      return t('Unique')
    case 'fulltext':
      return t('Fulltext')
    case 'spatial':
      return t('Spatial')
    default:
      return localizeResourceStatusLabel(type, t)
  }
}

export function localizePostgresBackendTypeLabel(
  backendType: string | null,
  t: Translator,
): string {
  const label = formatPostgresBackendTypeLabel(backendType)
  if (label === '-') return label
  if (label === 'Unknown') return t('Unknown')
  return t(label)
}

export function localizePostgresConnectionStateLabel(
  state: string | null,
  backendType: string | null,
  t: Translator,
): string {
  const label = formatPostgresConnectionStateLabel(state, backendType)
  if (label === '-') return label
  if (label === 'System') return t('System')

  if (state?.trim()) {
    switch (formatConnectionStateLabel(state.trim())) {
      case 'Active':
        return t('Active')
      case 'Idle':
        return t('Idle')
      case 'Idle in transaction':
        return t('Idle in transaction')
      case 'Idle in transaction (aborted)':
        return t('Idle in transaction (aborted)')
      case 'Fastpath function call':
        return t('Fastpath function call')
      case 'Disabled':
        return t('Disabled')
      default:
        break
    }
  }

  return localizeResourceStatusLabel(label, t)
}

export function localizePostgresIndexAlgorithmLabel(
  algorithm: string | null | undefined,
  t: Translator,
): string {
  return t(getPostgresIndexAlgorithmLabel(algorithm))
}

export function localizeMysqlBackendTypeLabel(
  backendType: string | null,
  t: Translator,
): string {
  const label = formatMysqlBackendTypeLabel(backendType)
  if (label === '-') return label
  if (label === 'Unknown') return t('Unknown')
  return t(label)
}

export function localizeMysqlConnectionStateLabel(
  state: string | null,
  backendType: string | null,
  t: Translator,
): string {
  const label = formatMysqlConnectionStateLabel(state, backendType)
  if (label === '-') return label
  if (label === 'System') return t('System')

  if (state?.trim()) {
    switch (formatConnectionStateLabel(state.trim())) {
      case 'Active':
        return t('Active')
      case 'Idle':
        return t('Idle')
      case 'Idle in transaction':
        return t('Idle in transaction')
      case 'Idle in transaction (aborted)':
        return t('Idle in transaction (aborted)')
      case 'Fastpath function call':
        return t('Fastpath function call')
      case 'Disabled':
        return t('Disabled')
      default:
        break
    }
  }

  return localizeResourceStatusLabel(label, t)
}

export function localizeMysqlIndexAlgorithmLabel(
  algorithm: string | null | undefined,
  t: Translator,
): string {
  return t(getMysqlIndexAlgorithmLabel(algorithm))
}

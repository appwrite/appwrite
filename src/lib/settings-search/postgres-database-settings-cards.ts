import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const POSTGRES_DATABASE_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'general',
    title: 'Name',
    keywords: ['rename', 'display', 'database name'],
  },
  {
    sectionId: 'general',
    title: 'Details',
    keywords: ['id', 'created', 'updated', 'status', 'paused', 'version'],
  },
  {
    sectionId: 'general',
    title: 'Delete database',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'compute',
    title: 'Compute tier',
    keywords: ['tier', 'cpu', 'memory', 'upgrade', 'specification', 'price'],
  },
  {
    sectionId: 'availability',
    title: 'High availability',
    keywords: ['replica', 'replicas', 'sync', 'failover', 'ha', 'topology', 'cluster'],
  },
  {
    sectionId: 'network',
    title: 'Network',
    keywords: ['ip', 'allowlist', 'cidr', 'idle', 'timeout'],
  },
  {
    sectionId: 'backups',
    title: 'Point-in-time recovery (PITR)',
    keywords: ['pitr', 'backup', 'retention', 'restore', 'recovery'],
  },
  {
    sectionId: 'storage',
    title: 'Storage',
    keywords: ['autoscaling', 'disk', 'threshold', 'gb'],
  },
  {
    sectionId: 'connections',
    title: 'Connection pooler',
    keywords: ['pool', 'pooler', 'transaction', 'session', 'splitting'],
  },
  {
    sectionId: 'maintenance',
    title: 'Maintenance window',
    keywords: ['window', 'utc', 'day', 'hour', 'upgrade', 'weekly'],
  },
]

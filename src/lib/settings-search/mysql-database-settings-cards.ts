import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const MYSQL_DATABASE_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
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
    sectionId: 'replication',
    title: 'Read replicas',
    keywords: ['replica', 'replicas', 'failover', 'ha', 'topology', 'cluster'],
  },
  {
    sectionId: 'replication',
    title: 'Primary instance',
    keywords: ['primary', 'main', 'leader', 'failover', 'promote', 'promotion'],
  },
  {
    sectionId: 'replication',
    title: 'Sync mode',
    keywords: ['sync', 'async', 'synchronous', 'quorum', 'replication'],
  },
  {
    sectionId: 'network',
    title: 'Network',
    keywords: ['ip', 'allowlist', 'cidr', 'idle', 'timeout'],
  },
  {
    sectionId: 'pitr',
    title: 'Point-in-time recovery (PITR)',
    keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
  },
  {
    sectionId: 'storage',
    title: 'Storage',
    keywords: ['autoscaling', 'disk', 'threshold', 'gb'],
  },
  {
    sectionId: 'maintenance',
    title: 'Maintenance window',
    keywords: ['window', 'utc', 'day', 'hour', 'upgrade', 'weekly'],
  },
]

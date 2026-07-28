import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const DATABASE_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'general',
    title: 'Name',
    keywords: ['rename', 'display', 'database name'],
  },
  {
    sectionId: 'general',
    title: 'Details',
    keywords: ['id', 'created', 'updated', 'enabled', 'disabled', 'status'],
  },
  {
    sectionId: 'general',
    title: 'Delete database',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'specification',
    title: 'Specification',
    keywords: [
      'tier',
      'cpu',
      'memory',
      'upgrade',
      'specification',
      'price',
      'serverless',
      'connections',
    ],
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
    sectionId: 'security',
    title: 'Permissions',
    keywords: ['permissions', 'rls', 'row level', 'access', 'security'],
  },
]

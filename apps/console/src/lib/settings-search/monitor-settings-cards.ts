import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export const MONITOR_SETTINGS_CARD_INDEX: SettingsCardIndexEntry[] = [
  {
    sectionId: 'general',
    title: 'Details',
    keywords: ['id', 'created', 'updated', 'identifiers', 'timestamps'],
  },
  {
    sectionId: 'general',
    title: 'Name',
    keywords: ['rename', 'display', 'monitor name'],
  },
  {
    sectionId: 'general',
    title: 'Status',
    keywords: ['enabled', 'paused', 'pause', 'resume', 'toggle'],
  },
  {
    sectionId: 'general',
    title: 'Delete monitor',
    keywords: ['delete', 'remove', 'destroy', 'danger'],
  },
  {
    sectionId: 'condition',
    title: 'Condition',
    keywords: ['metric', 'threshold', 'window', 'comparator', 'chart'],
  },
  {
    sectionId: 'condition',
    title: 'Target',
    keywords: ['url', 'host', 'port', 'domain', 'interval', 'timeout'],
  },
  {
    sectionId: 'notifications',
    title: 'Recipients',
    keywords: ['notify', 'roles', 'owners', 'developers', 'escalation'],
  },
  {
    sectionId: 'notifications',
    title: 'Severity',
    keywords: ['level', 'critical', 'warning', 'info', 'error'],
  },
  {
    sectionId: 'notifications',
    title: 'Cooldown',
    keywords: ['cooldown', 'repeat', 'frequency', 'throttle'],
  },
]

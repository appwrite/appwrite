import {
  CalendarClock,
  Cpu,
  Globe,
  HardDrive,
  History,
  Puzzle,
  Settings,
  ShieldCheck,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export type PostgresDatabaseSettingsPathSuffix =
  | ''
  | 'compute'
  | 'replication'
  | 'network'
  | 'pitr'
  | 'storage'
  | 'extensions'
  | 'maintenance'

export type PostgresDatabaseSettingsNavItem = {
  id: string
  label: string
  pathSuffix: PostgresDatabaseSettingsPathSuffix
  icon: LucideIcon
  keywords: string[]
  /** When false, item is omitted from nav (e.g. cloud-only features). */
  visible?: boolean
}

export const POSTGRES_DATABASE_SETTINGS_NAV: PostgresDatabaseSettingsNavItem[] =
  [
    {
      id: 'general',
      label: 'General',
      pathSuffix: '',
      icon: Settings,
      keywords: [
        'general',
        'name',
        'identity',
        'status',
        'paused',
        'running',
        'details',
        'id',
        'copy',
        'created',
        'updated',
        'identifiers',
        'delete',
        'remove',
        'trash',
        'destroy',
        'danger',
      ],
    },
    {
      id: 'compute',
      label: 'Compute',
      pathSuffix: 'compute',
      icon: Cpu,
      keywords: [
        'compute',
        'tier',
        'specification',
        'cpu',
        'memory',
        'connections',
        'upgrade',
        'vcpu',
        'price',
      ],
    },
    {
      id: 'replication',
      label: 'Replication',
      pathSuffix: 'replication',
      icon: ShieldCheck,
      keywords: [
        'replication',
        'replica',
        'replicas',
        'failover',
        'primary',
        'promote',
        'sync',
        'async',
        'ha',
      ],
    },
    {
      id: 'network',
      label: 'Network',
      pathSuffix: 'network',
      icon: Globe,
      keywords: [
        'network',
        'ip',
        'allowlist',
        'cidr',
        'idle',
        'timeout',
        'firewall',
        'access',
      ],
    },
    {
      id: 'pitr',
      label: 'PITR',
      pathSuffix: 'pitr',
      icon: History,
      keywords: [
        'pitr',
        'point in time',
        'point-in-time',
        'recovery',
        'retention',
        'restore',
      ],
    },
    {
      id: 'storage',
      label: 'Storage',
      pathSuffix: 'storage',
      icon: HardDrive,
      keywords: [
        'storage',
        'disk',
        'autoscaling',
        'threshold',
        'gb',
        'expansion',
      ],
    },
    {
      id: 'extensions',
      label: 'Extensions',
      pathSuffix: 'extensions',
      icon: Puzzle,
      keywords: [
        'extensions',
        'extension',
        'pgvector',
        'postgis',
        'install',
        'uninstall',
        'plugin',
      ],
    },
    {
      id: 'maintenance',
      label: 'Maintenance',
      pathSuffix: 'maintenance',
      icon: CalendarClock,
      keywords: [
        'maintenance',
        'window',
        'upgrade',
        'utc',
        'schedule',
        'weekly',
      ],
    },
  ]

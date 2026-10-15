import { Cpu, Settings, Shield, ShieldCheck } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { SettingsCardIndexEntry } from '@/lib/settings-search'

export type DatabaseSettingsPathSuffix =
  | ''
  | 'specification'
  | 'replication'
  | 'security'

export type DatabaseSettingsNavItem = {
  id: string
  label: string
  pathSuffix: DatabaseSettingsPathSuffix
  icon: LucideIcon
  keywords: string[]
  /** When false, item is omitted from nav (e.g. serverless product DBs). */
  visible?: boolean
}

export type DatabaseSettingsVisibility = {
  /** `databaseSpecifications` profile feature. */
  showSpecification: boolean
  /** Replication needs a dedicated database that supports replicas. */
  showReplication: boolean
}

export function isDatabaseSettingsSectionVisible(
  sectionId: string,
  visibility: DatabaseSettingsVisibility,
): boolean {
  if (sectionId === 'specification') return visibility.showSpecification
  if (sectionId === 'replication') return visibility.showReplication
  return true
}

export function visibleDatabaseSettingsNav(
  visibility: DatabaseSettingsVisibility,
): DatabaseSettingsNavItem[] {
  return DATABASE_SETTINGS_NAV.filter(
    (item) =>
      item.visible !== false &&
      isDatabaseSettingsSectionVisible(item.id, visibility),
  )
}

export function visibleDatabaseSettingsCards(
  cards: SettingsCardIndexEntry[],
  visibility: DatabaseSettingsVisibility,
): SettingsCardIndexEntry[] {
  return cards.filter((card) =>
    isDatabaseSettingsSectionVisible(card.sectionId, visibility),
  )
}

export const DATABASE_SETTINGS_NAV: DatabaseSettingsNavItem[] = [
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
      'enabled',
      'disabled',
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
    id: 'specification',
    label: 'Specification',
    pathSuffix: 'specification',
    icon: Cpu,
    keywords: [
      'specification',
      'compute',
      'tier',
      'cpu',
      'memory',
      'connections',
      'upgrade',
      'serverless',
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
    id: 'security',
    label: 'Security',
    pathSuffix: 'security',
    icon: Shield,
    keywords: [
      'security',
      'permissions',
      'rls',
      'row level',
      'access',
      'roles',
    ],
  },
]

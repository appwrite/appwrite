import { Cpu, Settings, Shield } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export type DatabaseSettingsPathSuffix = '' | 'specification' | 'security'

export type DatabaseSettingsNavItem = {
  id: string
  label: string
  pathSuffix: DatabaseSettingsPathSuffix
  icon: LucideIcon
  keywords: string[]
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

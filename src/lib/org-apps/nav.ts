import type { SettingsLayoutNavItem } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import {
  FileText,
  Key,
  KeyRound,
  LifeBuoy,
  Rocket,
  Scale,
} from 'lucide-react'

export type OrgAppSettingsSectionId =
  | 'general'
  | 'oauth'
  | 'legal'
  | 'support'
  | 'secrets'
  | 'settings'

export function buildOrgAppSettingsNavItems(
  orgId: string,
  appId: string,
  options?: { showSecrets?: boolean },
): SettingsLayoutNavItem[] {
  const params = { orgId, appId }
  const items: SettingsLayoutNavItem[] = [
    {
      id: 'general',
      label: 'General',
      icon: FileText,
      to: '/organizations/$orgId/apps/$appId',
      params,
      keywords: ['name', 'description', 'tagline', 'category', 'listing', 'delete'],
    },
    {
      id: 'oauth',
      label: 'OAuth client',
      icon: KeyRound,
      to: '/organizations/$orgId/apps/$appId/oauth',
      params,
      keywords: ['redirect', 'logout', 'pkce', 'device flow', 'public'],
    },
  ]

  if (options?.showSecrets) {
    items.push({
      id: 'secrets',
      label: 'OAuth secrets',
      icon: Key,
      to: '/organizations/$orgId/apps/$appId/secrets',
      params,
      keywords: ['secret', 'credentials', 'confidential', 'oauth'],
    })
  }

  items.push(
    {
      id: 'legal',
      label: 'Legal',
      icon: Scale,
      to: '/organizations/$orgId/apps/$appId/legal',
      params,
      keywords: ['privacy', 'terms', 'deletion', 'policy'],
    },
    {
      id: 'support',
      label: 'Support',
      icon: LifeBuoy,
      to: '/organizations/$orgId/apps/$appId/support',
      params,
      keywords: ['support', 'contacts', 'email', 'help'],
    },
    {
      id: 'settings',
      label: 'Publish',
      icon: Rocket,
      to: '/organizations/$orgId/apps/$appId/settings',
      params,
      keywords: ['publish', 'enabled', 'draft', 'branding', 'logo', 'homepage', 'images'],
    },
  )

  return items
}

export function getOrgAppSettingsSectionId(
  pathname: string,
): OrgAppSettingsSectionId {
  const parts = pathname.split('/').filter(Boolean)
  const appsIndex = parts.findIndex((part) => part === 'apps')
  if (appsIndex < 0) return 'general'

  const section = parts[appsIndex + 2]
  if (section === 'oauth') return 'oauth'
  if (section === 'legal') return 'legal'
  if (section === 'support') return 'support'
  if (section === 'secrets') return 'secrets'
  if (section === 'settings') return 'settings'
  return 'general'
}

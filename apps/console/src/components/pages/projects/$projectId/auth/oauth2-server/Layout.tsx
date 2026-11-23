import { useMemo } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { KeyRound, Server } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { View as OAuth2ServerSettingsView } from './settings/View'
import { View as OAuth2ServerAppsView } from './apps/View'
import { useT } from '@/lib/i18n/translate'

const OAUTH2_SERVER_NAV_ITEMS = [
  {
    id: 'server',
    label: 'Server',
    to: '/projects/$projectId/auth/oauth2-server',
    icon: Server,
    keywords: ['settings', 'status', 'integration', 'tokens', 'discovery'],
  },
  {
    id: 'apps',
    label: 'Apps',
    to: '/projects/$projectId/auth/oauth2-server/apps',
    icon: KeyRound,
    keywords: ['clients', 'oauth', 'redirect', 'secrets'],
  },
] as const

export type OAuth2ServerSubTab = (typeof OAUTH2_SERVER_NAV_ITEMS)[number]['id']

function parseOAuth2ServerSubTab(pathname: string): OAuth2ServerSubTab {
  const pathParts = pathname.split('/').filter(Boolean)
  const authIndex = pathParts.findIndex((part) => part === 'auth')
  if (authIndex >= 0 && pathParts[authIndex + 1] === 'oauth2-server') {
    const subTab = pathParts[authIndex + 2]
    if (subTab === 'apps') return 'apps'
  }
  return 'server'
}

interface OAuth2ServerLayoutProps {
  projectId: string
}

export function OAuth2ServerLayout({ projectId }: OAuth2ServerLayoutProps) {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()

  const subTab = useMemo(
    () => parseOAuth2ServerSubTab(location.pathname),
    [location.pathname],
  )

  const navItems = useMemo(
    () =>
      OAUTH2_SERVER_NAV_ITEMS.map((item) => ({
        ...item,
        label: t(item.label),
        keywords: [...item.keywords],
        params: { projectId },
      })),
    [projectId, t],
  )

  return (
    <SettingsLayoutShell
      navItems={[...navItems]}
      activeSectionId={subTab}
      cardIndex={[]}
      onNavigateToSection={(sectionId) => {
        const item = navItems.find((entry) => entry.id === sectionId)
        if (item) {
          navigate({
            to: item.to as '/',
            params: { projectId },
          })
        }
      }}
      searchPlaceholder={t('Search OAuth2 server...')}
      mobileNavAriaLabel={t('OAuth2 server section')}
      desktopNavAriaLabel={t('OAuth2 server navigation')}
    >
      {subTab === 'apps' ? (
        <OAuth2ServerAppsView projectId={projectId} />
      ) : (
        <OAuth2ServerSettingsView projectId={projectId} />
      )}
    </SettingsLayoutShell>
  )
}

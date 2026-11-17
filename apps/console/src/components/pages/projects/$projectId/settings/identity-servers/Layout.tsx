import { useMemo, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Network } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { useT } from '@/lib/i18n/translate'

export type IdentityServersSubTab = 'ldap'

const NAV_ITEMS = [
  {
    id: 'ldap' as const,
    label: 'LDAP',
    group: 'Identity servers',
    to: '/projects/$projectId/settings/identity-servers/ldap' as const,
    icon: Network,
    keywords: [
      'ldap',
      'ldaps',
      'starttls',
      'active directory',
      'openldap',
      'directory',
      'bind',
      'base dn',
      'user filter',
      'group',
      'certificate',
    ],
  },
]

type IdentityServersLayoutProps = {
  projectId: string
  activeSubTab: IdentityServersSubTab
  children: ReactNode
}

export function IdentityServersLayout({
  projectId,
  activeSubTab,
  children,
}: IdentityServersLayoutProps) {
  const t = useT()
  const navigate = useNavigate()

  const navItems = useMemo(
    () =>
      NAV_ITEMS.map((item) => ({
        ...item,
        label: t(item.label),
        group: t(item.group),
        params: { projectId },
      })),
    [projectId, t],
  )

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
      <SettingsLayoutShell
        navItems={navItems}
        activeSectionId={activeSubTab}
        cardIndex={[]}
        searchPlaceholder={t('Search identity servers...')}
        mobileNavAriaLabel={t('Identity servers section')}
        desktopNavAriaLabel={t('Identity servers navigation')}
        onNavigateToSection={(sectionId) => {
          const item = NAV_ITEMS.find((n) => n.id === sectionId)
          if (!item) return
          navigate({ to: item.to, params: { projectId } })
        }}
      >
        {children}
      </SettingsLayoutShell>
    </div>
  )
}

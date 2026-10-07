import { useMemo, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Clock, KeyRound, Mail, Users, UsersRound } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { useT } from '@/lib/i18n/translate'
import { POLICIES_SETTINGS_CARD_INDEX } from '@/lib/settings-search/policies-settings-cards'
import {
  PoliciesSettingsSearchProvider,
  usePoliciesSettingsSearch,
} from './PoliciesSettingsSearchContext'

export type PoliciesSubTab =
  | 'users'
  | 'sessions'
  | 'emails'
  | 'memberships'
  | 'passwords'

type PoliciesLayoutProps = {
  projectId: string
  activeSubTab: PoliciesSubTab
  children: ReactNode
}

const NAV_ITEMS = [
  {
    id: 'sessions' as const,
    label: 'Sessions',
    to: '/projects/$projectId/auth/policies/sessions' as const,
    icon: Clock,
    keywords: ['session', 'length', 'limit', 'invalidation', 'alerts'],
  },
  {
    id: 'users' as const,
    label: 'Users',
    to: '/projects/$projectId/auth/policies/users' as const,
    icon: Users,
    keywords: ['users', 'limit', 'maximum', 'count'],
  },
  {
    id: 'emails' as const,
    label: 'Emails',
    to: '/projects/$projectId/auth/policies/emails' as const,
    icon: Mail,
    keywords: [
      'email',
      'free',
      'gmail',
      'disposable',
      'mailinator',
      'alias',
      'signup',
    ],
  },
  {
    id: 'memberships' as const,
    label: 'Memberships',
    to: '/projects/$projectId/auth/policies/memberships' as const,
    icon: UsersRound,
    keywords: [
      'membership',
      'memberships',
      'privacy',
      'team',
      'mfa',
      'name',
      'hidden',
    ],
  },
  {
    id: 'passwords' as const,
    label: 'Passwords',
    to: '/projects/$projectId/auth/policies/passwords' as const,
    icon: KeyRound,
    keywords: [
      'password',
      'strength',
      'length',
      'history',
      'dictionary',
      'personal data',
    ],
  },
]

export function PoliciesLayout(props: PoliciesLayoutProps) {
  return (
    <PoliciesSettingsSearchProvider>
      <PoliciesLayoutContent {...props} />
    </PoliciesSettingsSearchProvider>
  )
}

function PoliciesLayoutContent({
  projectId,
  activeSubTab,
  children,
}: PoliciesLayoutProps) {
  const t = useT()
  const navigate = useNavigate()
  const { query: policiesSearchQuery, setQuery: setPoliciesSearchQuery } =
    usePoliciesSettingsSearch()

  const navItems = useMemo(
    () =>
      NAV_ITEMS.map((item) => ({
        ...item,
        label: t(item.label),
        params: { projectId },
      })),
    [projectId, t],
  )

  const activeLabel = t(
    NAV_ITEMS.find((item) => item.id === activeSubTab)?.label ?? 'Sessions',
  )

  return (
    <SettingsLayoutShell
      navItems={navItems}
      activeSectionId={activeSubTab}
      cardIndex={POLICIES_SETTINGS_CARD_INDEX}
      searchQuery={policiesSearchQuery}
      onSearchQueryChange={setPoliciesSearchQuery}
      searchPlaceholder={t('Search policies...')}
      mobileNavAriaLabel={t('Policies section')}
      desktopNavAriaLabel={t('Policies navigation')}
      onNavigateToSection={(sectionId) => {
        const item = NAV_ITEMS.find((n) => n.id === sectionId)
        if (!item) return
        navigate({
          to: item.to,
          params: { projectId },
        })
      }}
    >
      <h2 className="sr-only">
        {activeLabel} {t('policies')}
      </h2>
      {children}
    </SettingsLayoutShell>
  )
}

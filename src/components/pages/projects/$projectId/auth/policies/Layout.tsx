import { useMemo, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Clock, KeyRound, Mail, Users, UsersRound } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { POLICIES_SETTINGS_CARD_INDEX } from '@/lib/settings-search/policies-settings-cards'

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
    keywords: ['password', 'history', 'dictionary', 'personal data'],
  },
]

export function PoliciesLayout({
  projectId,
  activeSubTab,
  children,
}: PoliciesLayoutProps) {
  const navigate = useNavigate()

  const navItems = useMemo(
    () =>
      NAV_ITEMS.map((item) => ({
        ...item,
        params: { projectId },
      })),
    [projectId],
  )

  const activeLabel =
    NAV_ITEMS.find((item) => item.id === activeSubTab)?.label ?? 'Sessions'

  return (
    <SettingsLayoutShell
      navItems={navItems}
      activeSectionId={activeSubTab}
      cardIndex={POLICIES_SETTINGS_CARD_INDEX}
      searchPlaceholder="Search policies..."
      mobileNavAriaLabel="Policies section"
      desktopNavAriaLabel="Policies navigation"
      onNavigateToSection={(sectionId) => {
        const item = NAV_ITEMS.find((n) => n.id === sectionId)
        if (!item) return
        navigate({
          to: item.to,
          params: { projectId },
        })
      }}
    >
      <h2 className="sr-only">{activeLabel} policies</h2>
      {children}
    </SettingsLayoutShell>
  )
}

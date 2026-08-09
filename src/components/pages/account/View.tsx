import { useCallback, useEffect, useMemo, useState } from 'react'
import { Outlet, useLocation, useNavigate } from '@tanstack/react-router'
import {
  CreditCard,
  Gift,
  LogOut,
  MapPin,
  Monitor,
  Package,
  Settings,
  Shield,
} from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { ACCOUNT_SETTINGS_CARD_INDEX } from '@/lib/settings-search/account-settings-cards'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { registerCommandCenterOpener } from '@/lib/command-center/opener-bridge'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { useT } from '@/lib/i18n/translate'

export type AccountSectionId =
  | 'overview'
  | 'security'
  | 'sessions'
  | 'applications'
  | 'affiliates'
  | 'payment-methods'
  | 'billing-addresses'

const BILLING_SECTIONS = new Set<AccountSectionId>([
  'payment-methods',
  'billing-addresses',
])

const AFFILIATES_SECTIONS = new Set<AccountSectionId>(['affiliates'])

export function View() {
  const location = useLocation()
  const navigate = useNavigate()
  const { account, signOut } = useAuth()
  const { features } = useConsoleProfile()
  const t = useT()
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [commandCenterInitialSubPage, setCommandCenterInitialSubPage] =
    useState<string | null>(null)
  const [settingsNavSearch, setSettingsNavSearch] = useState('')

  useScrollToCard()

  const activeSection = useMemo((): AccountSectionId => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const accountIndex = pathParts.findIndex((part) => part === 'account')

    if (accountIndex >= 0) {
      const section = pathParts[accountIndex + 1]
      if (section === 'security') return 'security'
      if (section === 'sessions') return 'sessions'
      if (section === 'applications') return 'applications'
      if (section === 'affiliates') return 'affiliates'
      if (section === 'payment-methods') return 'payment-methods'
      if (section === 'billing-addresses') return 'billing-addresses'
    }

    return 'overview'
  }, [location.pathname])

  const accountNavItems = useMemo(() => {
    const items = [
      {
        id: 'overview',
        label: t('General'),
        to: '/account',
        icon: Settings,
        keywords: ['general', 'overview', 'profile', 'name', 'email', 'delete'],
      },
      {
        id: 'security',
        label: t('Security'),
        to: '/account/security',
        icon: Shield,
        keywords: ['password', 'mfa', '2fa', 'identities', 'oauth'],
      },
      {
        id: 'sessions',
        label: t('Sessions'),
        to: '/account/sessions',
        icon: Monitor,
        keywords: ['sessions', 'devices', 'logout', 'revoke'],
      },
      {
        id: 'applications',
        label: t('Applications'),
        to: '/account/applications',
        icon: Package,
        keywords: ['applications', 'oauth', 'authorized', 'consent', 'revoke'],
      },
      ...(features.affiliates
        ? [
            {
              id: 'affiliates',
              label: t('Affiliates'),
              to: '/account/affiliates',
              icon: Gift,
              keywords: [
                'affiliate',
                'referral',
                'credits',
                'earn',
                'reward',
                'pro',
                'link',
                'invite',
                'clicks',
                'signups',
              ],
            },
          ]
        : []),
      ...(features.billing
        ? [
            {
              id: 'payment-methods',
              label: t('Payment methods'),
              to: '/account/payment-methods',
              icon: CreditCard,
              keywords: ['card', 'credit card', 'stripe', 'payment method'],
            },
            {
              id: 'billing-addresses',
              label: t('Billing addresses'),
              to: '/account/billing-addresses',
              icon: MapPin,
              keywords: ['address', 'country', 'city', 'postal', 'zip'],
            },
          ]
        : []),
    ]

    return items
  }, [features.affiliates, features.billing, t])

  const accountSettingsCardIndex = useMemo(() => {
    return ACCOUNT_SETTINGS_CARD_INDEX.filter((entry) => {
      if (entry.sectionId === 'affiliates') {
        return features.affiliates
      }
      if (
        entry.sectionId === 'payment-methods' ||
        entry.sectionId === 'billing-addresses'
      ) {
        return features.billing
      }
      if (entry.title === 'Identities') {
        return features.accountIdentities
      }
      if (entry.title === 'Multi-factor authentication') {
        return features.accountMfa
      }
      return true
    })
  }, [
    features.affiliates,
    features.billing,
    features.accountIdentities,
    features.accountMfa,
  ])

  useEffect(() => {
    if (!features.billing && BILLING_SECTIONS.has(activeSection)) {
      navigate({ to: '/account', replace: true })
    }
  }, [activeSection, features.billing, navigate])

  useEffect(() => {
    if (!features.affiliates && AFFILIATES_SECTIONS.has(activeSection)) {
      navigate({ to: '/account', replace: true })
    }
  }, [activeSection, features.affiliates, navigate])

  const openAccountCommandCenter = useCallback(() => {
    setCommandCenterInitialSubPage(null)
    setCommandCenterOpen(true)
  }, [])

  const openAccountShortcutsHelp = useCallback(() => {
    setCommandCenterInitialSubPage('shortcuts')
    setCommandCenterOpen(true)
  }, [])

  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setCommandCenterInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: openAccountCommandCenter,
    onOpenShortcutsHelp: openAccountShortcutsHelp,
  })

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => setCommandCenterOpen(true),
        }}
        showFooter
        containerClassName="account-layout-container"
      >
        <ServiceHeader
          title={account?.name || account?.email || t('Account')}
          fullWidthBorder
          titleRightContent={
            <button
              type="button"
              data-testid="account-logout"
              onClick={async () => {
                await signOut()
              }}
              className="flex items-center gap-2 rounded-md px-3 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <LogOut className="h-4 w-4" />
              {t('Logout')}
            </button>
          }
        />

        <div className="min-w-0 flex-1">
          <div className="mx-auto min-w-0 max-w-7xl px-4 py-4 sm:px-6">
            <SettingsLayoutShell
              navItems={accountNavItems}
              activeSectionId={activeSection}
              cardIndex={accountSettingsCardIndex}
              searchQuery={settingsNavSearch}
              onSearchQueryChange={setSettingsNavSearch}
              onNavigateToSection={(sectionId) => {
                const item = accountNavItems.find((n) => n.id === sectionId)
                if (item) {
                  navigate({ to: item.to as '/' })
                }
              }}
            >
              <Outlet />
            </SettingsLayoutShell>
          </div>
        </div>
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={(open) => {
          setCommandCenterOpen(open)
          if (!open) setCommandCenterInitialSubPage(null)
        }}
        context="account"
        initialSubPage={commandCenterInitialSubPage}
        onInitialSubPageConsumed={() => setCommandCenterInitialSubPage(null)}
      />
    </>
  )
}

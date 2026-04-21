import { useMemo, useState } from 'react'
import { useLocation, Link } from '@tanstack/react-router'
import { type Tab } from '../projects/$projectId/shared/ServiceHeader'
import { AccountOverview } from './Overview'
import { AccountSessions } from './Sessions'
import { AccountPayments } from './Payments'
import { User, CreditCard, LogOut } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { cn } from '@/lib/utils'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'

const BASE_TABS: Tab[] = [
  {
    id: 'overview',
    label: 'Overview',
    to: '/account',
  },
  {
    id: 'sessions',
    label: 'Sessions',
    to: '/account/sessions',
  },
  {
    id: 'payments',
    label: 'Payments',
    to: '/account/payments',
  },
]

interface ViewProps {
  activeTab?: string
}

export function View({ activeTab: tabProp }: ViewProps) {
  const location = useLocation()
  const { account, signOut } = useAuth()
  const { features } = useConsoleProfile()
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

  useScrollToCard()

  const tabs = useMemo(
    () =>
      features.billing
        ? BASE_TABS
        : BASE_TABS.filter((t) => t.id !== 'payments'),
    [features.billing],
  )

  // Command center shortcut (Cmd+K / Ctrl+K)
  useKeyboardShortcut('meta+k', () => {
    setCommandCenterOpen(true)
  })

  useKeyboardShortcut('control+k', () => {
    setCommandCenterOpen(true)
  })

  // Focus search shortcut (/)
  useKeyboardShortcut('/', (e) => {
    e.preventDefault()
    setCommandCenterOpen(true)
  })

  // Derive active tab from pathname if prop is not provided
  const activeTab = useMemo(() => {
    if (tabProp) return tabProp

    // Extract tab from pathname
    // Pattern: /account or /account/:tab
    const pathParts = location.pathname.split('/').filter(Boolean)
    const accountIndex = pathParts.findIndex((part) => part === 'account')

    if (accountIndex >= 0) {
      // Check if there's a tab segment after 'account'
      if (pathParts[accountIndex + 1]) {
        const tabFromPath = pathParts[accountIndex + 1]
        const validTabs = features.billing
          ? ['overview', 'sessions', 'payments']
          : ['overview', 'sessions']
        if (validTabs.includes(tabFromPath)) {
          return tabFromPath
        }
      }
    }

    // Default to overview for index route (/account or /account/)
    return 'overview'
  }, [tabProp, location.pathname, features.billing])

  const getEmptyStateContent = () => {
    switch (activeTab) {
      case 'sessions':
        return {
          icon: LogOut,
          title: 'Sessions',
          description: 'Manage your active sessions and devices',
        }
      case 'payments':
        return {
          icon: CreditCard,
          title: 'Payments',
          description: 'Manage your payment methods and billing',
        }
      default:
        return {
          icon: User,
          title: 'Account',
          description: 'This section is under construction',
        }
    }
  }

  const emptyState = getEmptyStateContent()
  const Icon = emptyState.icon

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => setCommandCenterOpen(true),
        }}
        showFooter
        containerClassName="account-layout-container"
      >
        {/* Account Header with Tabs */}
        <div>
          {/* Title Row */}
          <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <h1 className="text-[20px] font-semibold text-foreground">
              {account?.name || account?.email || 'Account'}
            </h1>
            <button
              onClick={async () => {
                await signOut()
              }}
              className="flex items-center gap-2 rounded-md px-3 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <LogOut className="h-4 w-4" />
              Logout
            </button>
          </div>

          {/* Tabs Row */}
          <div className="border-b border-border">
            <div
              className="mx-auto flex w-full max-w-7xl gap-0 overflow-x-auto px-4 sm:px-6"
              role="tablist"
            >
              {tabs.map((tab) => {
                const isActive = activeTab === tab.id
                return (
                  <Link
                    key={tab.id}
                    to={tab.to as unknown}
                    replace
                    role="tab"
                    aria-selected={isActive}
                    className={cn(
                      'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                      isActive
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground/80',
                    )}
                  >
                    {tab.label}
                    {isActive && (
                      <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                    )}
                  </Link>
                )
              })}
            </div>
          </div>
        </div>

        {/* Main Content */}
        <div className="flex-1">
          {activeTab === 'overview' ? (
            <AccountOverview />
          ) : activeTab === 'sessions' ? (
            <AccountSessions />
          ) : activeTab === 'payments' ? (
            <AccountPayments />
          ) : (
            <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
              <div className="flex h-full min-h-[400px] items-center justify-center">
                <div className="text-center">
                  <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                    <Icon className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <h2 className="mb-1.5 text-[15px] font-medium text-foreground">
                    {emptyState.title}
                  </h2>
                  <p className="text-[13px] text-muted-foreground">
                    {emptyState.description}
                  </p>
                </div>
              </div>
            </div>
          )}
        </div>
      </ConsoleLayout>

      {/* Command Center */}
      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}

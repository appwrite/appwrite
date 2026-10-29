import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from '@tanstack/react-router'
import {
  Boxes,
  ChevronLeft,
  CreditCard,
  Globe,
  LayoutDashboard,
  Settings,
  Users,
  X,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleHeaderLogo } from '@/components/global/shared/ConsoleHeaderLogo'
import { OrganizationSwitcher } from '@/components/global/layout/OrganizationSwitcher'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  OFFCANVAS_START_CLOSED,
  SIDEBAR_EDGE_TOGGLE_OVERFLOW,
} from '@/lib/layout/offcanvas-classes'
import {
  SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import { getConsoleHeaderLogoClass } from '@/lib/html-theme'
import { useSidebarCollapsed } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useTheme } from 'next-themes'

type PartnerNavItem = {
  id: string
  label: string
  icon: LucideIcon
  to:
    | '/partners/$partnerId'
    | '/partners/$partnerId/clients'
    | '/partners/$partnerId/domains'
    | '/partners/$partnerId/projects'
    | '/partners/$partnerId/billing'
    | '/partners/$partnerId/settings'
}

const NAV_ITEMS: PartnerNavItem[] = [
  {
    id: 'overview',
    label: 'Overview',
    icon: LayoutDashboard,
    to: '/partners/$partnerId',
  },
  {
    id: 'clients',
    label: 'Clients',
    icon: Users,
    to: '/partners/$partnerId/clients',
  },
  {
    id: 'domains',
    label: 'Domains',
    icon: Globe,
    to: '/partners/$partnerId/domains',
  },
  {
    id: 'projects',
    label: 'Projects',
    icon: Boxes,
    to: '/partners/$partnerId/projects',
  },
  {
    id: 'billing',
    label: 'Billing',
    icon: CreditCard,
    to: '/partners/$partnerId/billing',
  },
]

const SETTINGS_ITEM: PartnerNavItem = {
  id: 'settings',
  label: 'Settings',
  icon: Settings,
  to: '/partners/$partnerId/settings',
}

type PartnerSidebarProps = {
  partnerId: string
  activeSection: string
  mobileOpen: boolean
  onMobileClose: () => void
}

export function PartnerSidebar({
  partnerId,
  activeSection,
  mobileOpen,
  onMobileClose,
}: PartnerSidebarProps) {
  const { account } = useAuth()
  const accountWithPrefs = account as
    | { prefs?: Record<string, unknown> }
    | undefined
  const { collapsed, setCollapsed } = useSidebarCollapsed(accountWithPrefs)
  const navRef = useRef<HTMLElement>(null)
  const [themeMounted, setThemeMounted] = useState(false)
  const { theme, resolvedTheme } = useTheme()

  useEffect(() => {
    setThemeMounted(true)
  }, [])

  const headerLogoClassName = getConsoleHeaderLogoClass(
    theme,
    resolvedTheme,
    themeMounted,
  )

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (!navRef.current) return
    const items = Array.from(
      navRef.current.querySelectorAll('[data-nav-item]'),
    ) as HTMLAnchorElement[]
    const currentIndex = items.findIndex(
      (item) => item === document.activeElement,
    )

    if (e.key === 'ArrowDown' || e.key === 'j') {
      e.preventDefault()
      const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0
      items[nextIndex]?.focus()
    } else if (e.key === 'ArrowUp' || e.key === 'k') {
      e.preventDefault()
      const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1
      items[prevIndex]?.focus()
    } else if (e.key === 'Home') {
      e.preventDefault()
      items[0]?.focus()
    } else if (e.key === 'End') {
      e.preventDefault()
      items[items.length - 1]?.focus()
    }
  }, [])

  const renderNavItem = (item: PartnerNavItem, isMobile = false) => {
    const isActive = activeSection === item.id
    const showLabel = !collapsed || isMobile
    const Icon = item.icon
    const linkContent = (
      <Link
        key={item.id}
        to={item.to}
        params={{ partnerId }}
        data-nav-item
        onClick={() => {
          if (isMobile) onMobileClose()
        }}
        className={cn(
          secondarySidebarNavLinkClassName(
            isActive,
            'cursor-pointer transition-colors duration-150',
          ),
          collapsed && !isMobile
            ? SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS
            : SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
          isMobile && 'gap-x-3 px-3 py-2.5 text-[14px]',
        )}
      >
        <Icon
          className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
        />
        {showLabel ? (
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
            {item.label}
          </span>
        ) : null}
      </Link>
    )

    if (collapsed && !isMobile) {
      return (
        <Tooltip key={item.id} delayDuration={0}>
          <TooltipTrigger asChild>{linkContent}</TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>{item.label}</p>
          </TooltipContent>
        </Tooltip>
      )
    }

    return linkContent
  }

  return (
    <TooltipProvider>
      <div
        className={cn(
          'relative z-20 hidden h-full flex-shrink-0 @[1024px]:block',
          'transition-[width] duration-150 ease-out',
          collapsed ? 'w-[60px]' : 'w-[220px]',
        )}
      >
        <aside className="console-sidebar flex h-full w-full flex-col overflow-hidden border-e border-border bg-background [transform:translateZ(0)] [backface-visibility:hidden]">
          <nav
            ref={navRef}
            className="overlay-scrollbar min-h-0 flex-1 space-y-0.5 overflow-y-auto overscroll-contain px-3 py-4"
            onKeyDown={handleKeyDown}
            role="navigation"
            aria-label="Partner"
          >
            {NAV_ITEMS.map((item) => renderNavItem(item))}
          </nav>
          <div className="flex h-[55px] w-full items-center border-t border-border px-3">
            <div className="w-full">{renderNavItem(SETTINGS_ITEM)}</div>
          </div>
        </aside>
        <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          className={cn(
            'absolute end-0 top-1/2 z-10 flex h-6 w-6 shrink-0 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            SIDEBAR_EDGE_TOGGLE_OVERFLOW,
          )}
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
          />
        </button>
      </div>

      <aside
        className={cn(
          'console-sidebar fixed start-0 top-0 z-[130] flex h-[100dvh] max-h-[100dvh] w-[280px] flex-col overflow-hidden border-e border-border bg-background',
          'transition-transform duration-200 ease-out [backface-visibility:hidden]',
          '@[1024px]:hidden',
          mobileOpen ? 'translate-x-0' : OFFCANVAS_START_CLOSED,
        )}
        role="dialog"
        aria-modal="true"
        aria-label="Partner navigation"
        inert={!mobileOpen ? true : undefined}
      >
        <div className="flex h-14 items-center justify-between px-4">
          <div className="flex items-center gap-2.5" aria-label="Appwrite">
            <ConsoleHeaderLogo
              className={cn('h-6 w-6 shrink-0', headerLogoClassName)}
            />
          </div>
          <button
            type="button"
            onClick={onMobileClose}
            className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close navigation"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="px-4 pb-3">
          <OrganizationSwitcher organizationId={partnerId} isMobile />
        </div>
        <nav
          className="overlay-scrollbar min-h-0 flex-1 space-y-0.5 overflow-y-auto overscroll-contain px-4 py-2"
          role="navigation"
          aria-label="Partner navigation"
        >
          {NAV_ITEMS.map((item) => renderNavItem(item, true))}
        </nav>
        <div className="border-t border-border px-4 py-3">
          {renderNavItem(SETTINGS_ITEM, true)}
        </div>
      </aside>
    </TooltipProvider>
  )
}

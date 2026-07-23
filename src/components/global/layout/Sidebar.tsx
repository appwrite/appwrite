import { useEffect, useRef, useCallback, useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import {
  OFFCANVAS_START_CLOSED,
  SIDEBAR_EDGE_TOGGLE_OVERFLOW,
} from '@/lib/layout/offcanvas-classes'
import {
  SECONDARY_SIDEBAR_GROUP_HEADING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useSidebarCollapsed,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import {
  canSeeProjectNavItem,
  canSeeUsageNav,
  canSeeActivityNav,
  canSeeProjects,
  canShowProjectSettings,
  canShowGetStartedSection,
} from '@/lib/console-access-checks'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  analyticsAttrs,
  getSidebarNavAnalyticsAction,
} from '@/lib/analytics-actions'
import {
  LayoutDashboard,
  Database,
  Users,
  Folder,
  Zap,
  MessageSquare,
  Settings,
  BarChart3,
  Key,
  ChevronLeft,
  X,
  Globe,
  Plug,
  Activity,
  Shield,
  ScanSearch,
  Package,
  FileText,
  BarChart2,
  AlertTriangle,
  Radio,
  ListTree,
  type LucideIcon,
} from 'lucide-react'
import { ProjectSelector } from '@/components/pages/projects/$projectId/shared/ProjectSelector'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { OnboardingCard } from './OnboardingCard'
import { useTheme } from 'next-themes'
import { getConsoleHeaderLogoClass } from '@/lib/html-theme'
import { ConsoleHeaderLogo } from '@/components/global/shared/ConsoleHeaderLogo'
import { useI18n } from '@/lib/i18n'

interface NavItem {
  id: string
  label: string
  icon: LucideIcon | 'imagine'
  path: string
  comingSoon?: boolean
}

interface NavCategory {
  label: string
  items: NavItem[]
}

interface SidebarCopy {
  sections: {
    connect: string
    build: string
    deploy: string
    observe: string
    protect: string
  }
  items: {
    overview: string
    apps: string
    apiKeys: string
    explorer: string
    auth: string
    databases: string
    storage: string
    functions: string
    messaging: string
    sites: string
    distribution: string
    activity: string
    realtime: string
    logs: string
    usage: string
    analytics: string
    errors: string
    firewall: string
    advisor: string
    settings: string
  }
  badges: {
    soon: string
  }
  accessibility: {
    mainNavigation: string
    mobileNavigation: string
    closeNavigation: string
    expandSidebar: string
    collapseSidebar: string
    comingSoonSuffix: string
  }
}

const getNavItems = (projectId: string, sidebarCopy: SidebarCopy) => {
  const overviewItem: NavItem = {
    id: 'overview',
    label: sidebarCopy.items.overview,
    icon: LayoutDashboard,
    path: `/projects/${projectId}`,
  }

  const navCategories: NavCategory[] = [
    {
      label: sidebarCopy.sections.connect,
      items: [
        {
          id: 'apps',
          label: sidebarCopy.items.apps,
          icon: Plug,
          path: `/projects/${projectId}/apps`,
        },
        {
          id: 'api-keys',
          label: sidebarCopy.items.apiKeys,
          icon: Key,
          path: `/projects/${projectId}/api-keys`,
        },
        {
          id: 'explorer',
          label: sidebarCopy.items.explorer,
          icon: ListTree,
          path: `/projects/${projectId}/explorer`,
        },
      ],
    },
    {
      label: sidebarCopy.sections.build,
      items: [
        {
          id: 'auth',
          label: sidebarCopy.items.auth,
          icon: Users,
          path: `/projects/${projectId}/auth`,
        },
        {
          id: 'databases',
          label: sidebarCopy.items.databases,
          icon: Database,
          path: `/projects/${projectId}/databases`,
        },
        {
          id: 'storage',
          label: sidebarCopy.items.storage,
          icon: Folder,
          path: `/projects/${projectId}/storage/-`,
        },
        {
          id: 'functions',
          label: sidebarCopy.items.functions,
          icon: Zap,
          path: `/projects/${projectId}/functions`,
        },
        {
          id: 'messaging',
          label: sidebarCopy.items.messaging,
          icon: MessageSquare,
          path: `/projects/${projectId}/messaging`,
        },
      ],
    },
    {
      label: sidebarCopy.sections.deploy,
      items: [
        {
          id: 'sites',
          label: sidebarCopy.items.sites,
          icon: Globe,
          path: `/projects/${projectId}/sites`,
        },
        {
          id: 'stores',
          label: sidebarCopy.items.distribution,
          icon: Package,
          path: `/projects/${projectId}/stores`,
          comingSoon: true,
        },
      ],
    },
    {
      label: sidebarCopy.sections.observe,
      items: [
        {
          id: 'activity',
          label: sidebarCopy.items.activity,
          icon: Activity,
          path: `/projects/${projectId}/activity`,
        },
        {
          id: 'realtime',
          label: sidebarCopy.items.realtime,
          icon: Radio,
          path: `/projects/${projectId}/realtime`,
        },
        {
          id: 'logs',
          label: sidebarCopy.items.logs,
          icon: FileText,
          path: `/projects/${projectId}/logs`,
          comingSoon: true,
        },
        {
          id: 'usage',
          label: sidebarCopy.items.usage,
          icon: BarChart3,
          path: `/projects/${projectId}/usage`,
        },
        {
          id: 'analytics',
          label: sidebarCopy.items.analytics,
          icon: BarChart2,
          path: `/projects/${projectId}/analytics`,
          comingSoon: true,
        },
        {
          id: 'errors',
          label: sidebarCopy.items.errors,
          icon: AlertTriangle,
          path: `/projects/${projectId}/errors`,
          comingSoon: true,
        },
      ],
    },
    {
      label: sidebarCopy.sections.protect,
      items: [
        {
          id: 'firewall',
          label: sidebarCopy.items.firewall,
          icon: Shield,
          path: `/projects/${projectId}/firewall`,
        },
        {
          id: 'advisor',
          label: sidebarCopy.items.advisor,
          icon: ScanSearch,
          path: `/projects/${projectId}/advisor`,
          comingSoon: true,
        },
      ],
    },
  ]

  const settingsItem: NavItem = {
    id: 'settings',
    label: sidebarCopy.items.settings,
    icon: Settings,
    path: `/projects/${projectId}/settings`,
  }

  return { overviewItem, navCategories, settingsItem }
}

interface ConsoleSidebarProps {
  projectId: string
  activeSection: string
  mobileOpen?: boolean
  onMobileClose?: () => void
  className?: string
}

export function ConsoleSidebar({
  projectId,
  activeSection,
  mobileOpen,
  onMobileClose,
  className,
}: ConsoleSidebarProps) {
  const { account } = useAuth()
  const { catalog } = useI18n()
  const sidebarCopy = catalog.app.sidebar
  const accountWithPrefs = account as
    | { prefs?: Record<string, unknown> }
    | undefined
  const { collapsed, setCollapsed } = useSidebarCollapsed(accountWithPrefs)
  const navRef = useRef<HTMLElement>(null)
  const { isDebugModeOpen } = useDebugMode()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access, isLoading: scopesLoading } = useOrganizationScopes(
    project?.teamId,
  )

  const { overviewItem, settingsItem } = getNavItems(projectId, sidebarCopy)

  const visibleCategories = useMemo(() => {
    const { navCategories: categories } = getNavItems(projectId, sidebarCopy)
    return categories
      .map((cat) => ({
        ...cat,
        items: (isDebugModeOpen
          ? cat.items
          : cat.items.filter((item) => !item.comingSoon)
        ).filter((item) => {
          if (item.id === 'usage')
            return features.usageStats && canSeeUsageNav(access, features)
          if (item.id === 'activity')
            return features.activity && canSeeActivityNav(access, features)
          if (item.id === 'firewall')
            return features.firewall && canSeeProjectNavItem(access, features, item.id)
          return canSeeProjectNavItem(access, features, item.id)
        }),
      }))
      .filter((cat) => cat.items.length > 0)
  }, [projectId, isDebugModeOpen, features, access, sidebarCopy])

  const showOverview = canSeeProjects(access, features)
  // Hide project Settings from left nav when user lacks write access (e.g. analyst).
  // When org roles are on, only show after scopes have loaded so we don't flash Settings.
  const showSettings =
    !features.orgRoles ||
    (!scopesLoading && canShowProjectSettings(access, features))
  const showGetStarted = canShowGetStartedSection(access, features)
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

  // Handle keyboard navigation within sidebar within sidebar
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

  const handleNavClick = useCallback(
    (_item: NavItem, isMobile: boolean) => {
      if (isMobile) onMobileClose?.()
    },
    [onMobileClose],
  )

  const renderNavItem = (item: NavItem, isMobile = false) => {
    const isActive = activeSection === item.id
    const isImagineIcon = item.icon === 'imagine'
    const navAnalytics = getSidebarNavAnalyticsAction(item.id)

    // Render the appropriate icon
    const renderIcon = () => {
      if (isImagineIcon) {
        return (
          <img
            src="/imagine-icon.svg"
            alt=""
            className={cn(
              'h-4 w-4 shrink-0',
              PUBLIC_ICON_MUTED_CLASSES,
              isMobile && 'h-[18px] w-[18px]',
            )}
          />
        )
      }
      const Icon = item.icon as LucideIcon
      return (
        <Icon
          className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
        />
      )
    }

    if (item.comingSoon) {
      const Icon = item.icon as LucideIcon
      const buttonContent = (
        <span
          key={item.id}
          className={cn(
            secondarySidebarNavLinkClassName(false, 'cursor-not-allowed text-muted-foreground/50'),
            collapsed && !isMobile
              ? SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS
              : SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
            isMobile && 'gap-x-3 px-3 py-2.5 text-[14px]',
          )}
        >
          <Icon
            className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
          />
          {(!collapsed || isMobile) && (
            <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
              {item.label}
            </span>
          )}
          {(!collapsed || isMobile) && (
            <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
              {sidebarCopy.badges.soon}
            </span>
          )}
        </span>
      )

      if (collapsed && !isMobile) {
        return (
          <Tooltip key={item.id} delayDuration={0}>
            <TooltipTrigger asChild>{buttonContent}</TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
              <p>{item.label}</p>
              <span className="ms-1 text-muted-foreground">
                {sidebarCopy.accessibility.comingSoonSuffix}
              </span>
            </TooltipContent>
          </Tooltip>
        )
      }

      return buttonContent
    }

    const linkContent = (
      <Link
        key={item.id}
        to={item.path}
        data-nav-item
        {...(navAnalytics ? analyticsAttrs(navAnalytics) : {})}
        onClick={() => handleNavClick(item, isMobile)}
        className={cn(
          secondarySidebarNavLinkClassName(isActive, 'transition-colors duration-150'),
          collapsed && !isMobile
            ? SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS
            : SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
          isMobile && 'gap-x-3 px-3 py-2.5 text-[14px]',
        )}
      >
        {renderIcon()}
        {(!collapsed || isMobile) && (
          <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
            {item.label}
          </span>
        )}
      </Link>
    )

    // Wrap with tooltip when collapsed (desktop only)
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

  const renderCategory = (category: NavCategory, isMobile = false) => (
    <div key={category.label} className="space-y-0.5">
      {(!collapsed || isMobile) && (
        <p
          className={cn(SECONDARY_SIDEBAR_GROUP_HEADING_CLASS, isMobile && 'px-3')}
        >
          {category.label}
        </p>
      )}
      {category.items.map((item) => renderNavItem(item, isMobile))}
    </div>
  )

  return (
    <TooltipProvider>
      {/* Desktop Sidebar wrapper - the toggle is rendered as a sibling of
          <aside> so it lives outside the GPU layer below. Keeping the
          translateZ/backface-visibility hack on the aside (not on the wrapper)
          stops iOS Safari from clipping the half of the toggle that overflows
          the sidebar's right edge. */}
      <div
        className={cn(
          /* z-20: main is a later flex sibling and would paint over the half-outside collapse toggle without this */
          'relative z-20 hidden h-full flex-shrink-0 @[1024px]:block',
          'transition-[width] duration-150 ease-out',
          collapsed ? 'w-[60px]' : 'w-[220px]',
          className,
        )}
      >
        <aside
          className={cn(
            'console-sidebar flex h-full w-full flex-col overflow-hidden border-e border-border bg-background',
            '[transform:translateZ(0)] [backface-visibility:hidden]',
          )}
        >
          {/* Main Navigation */}
          <nav
            ref={navRef}
            className="flex-1 space-y-6 overflow-y-auto px-3 py-4"
            onKeyDown={handleKeyDown}
            role="navigation"
            aria-label={sidebarCopy.accessibility.mainNavigation}
          >
            {/* Onboarding Card - only owners and developers */}
            {showGetStarted && (
              <OnboardingCard projectId={projectId} collapsed={collapsed} />
            )}

            {/* Overview */}
            {showOverview && (
              <div className="space-y-0.5">{renderNavItem(overviewItem)}</div>
            )}

            {visibleCategories.map((category) => renderCategory(category))}
          </nav>

          {/* Settings */}
          {showSettings && (
            <div className="flex h-[54px] w-full items-center border-t border-border px-3">
              <div className="w-full">{renderNavItem(settingsItem)}</div>
            </div>
          )}
        </aside>

        {/* Collapse Toggle - sibling of <aside>, positioned against the
            wrapper so it isn't clipped by the aside's GPU layer on iOS. */}
        <button
          {...analyticsAttrs('sidebar-collapse')}
          onClick={() => {
            setCollapsed(!collapsed)
          }}
          className={cn(
            'absolute end-0 top-1/2 z-10 flex h-6 w-6 shrink-0 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            SIDEBAR_EDGE_TOGGLE_OVERFLOW,
          )}
          aria-label={
            collapsed
              ? sidebarCopy.accessibility.expandSidebar
              : sidebarCopy.accessibility.collapseSidebar
          }
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
          />
        </button>
      </div>

      {/* Mobile Sidebar - GPU-accelerated transform; inert when closed so it's skipped in tab order */}
      <aside
        className={cn(
          'console-sidebar fixed start-0 top-0 z-[130] flex h-[100dvh] max-h-[100dvh] w-[280px] flex-col overflow-hidden border-e border-border bg-background',
          'transition-transform duration-200 ease-out [backface-visibility:hidden]',
          '@[1024px]:hidden',
          mobileOpen ? 'translate-x-0' : OFFCANVAS_START_CLOSED,
        )}
        role="dialog"
        aria-modal="true"
        aria-label={sidebarCopy.accessibility.mobileNavigation}
        inert={!mobileOpen ? true : undefined}
      >
        {/* Mobile Header */}
        <div className="flex h-14 items-center justify-between px-4">
          <div className="flex items-center gap-2.5" aria-label="Appwrite">
            <ConsoleHeaderLogo
              className={cn('h-6 w-6 shrink-0', headerLogoClassName)}
            />
          </div>
          <button
            onClick={onMobileClose}
            className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label={sidebarCopy.accessibility.closeNavigation}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Project Selector */}
        <div className="px-4 pb-3">
          <ProjectSelector projectId={projectId} isMobile />
        </div>

        {/* Mobile Navigation */}
        <nav
          className="flex-1 space-y-6 overflow-y-auto px-4 py-2"
          role="navigation"
          aria-label={sidebarCopy.accessibility.mobileNavigation}
        >
          {/* Onboarding Card - only owners and developers */}
          {showGetStarted && (
            <OnboardingCard projectId={projectId} collapsed={false} />
          )}

          {/* Overview */}
          {showOverview && (
            <div className="space-y-0.5">
              {renderNavItem(overviewItem, true)}
            </div>
          )}

          {visibleCategories.map((category) => renderCategory(category, true))}
        </nav>

        {/* Settings */}
        {showSettings && (
          <div className="border-t border-border px-4 py-3">
            {renderNavItem(settingsItem, true)}
          </div>
        )}
      </aside>
    </TooltipProvider>
  )
}

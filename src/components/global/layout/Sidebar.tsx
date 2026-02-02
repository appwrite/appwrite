import { useRef, useCallback, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  LayoutDashboard,
  Database,
  Users,
  HardDrive,
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
  FileWarning,
  Store,
  FileText,
  BarChart2,
  AlertTriangle,
  Radio,
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

const getNavItems = (projectId: string) => {
  const overviewItem: NavItem = {
    id: 'overview',
    label: 'Overview',
    icon: LayoutDashboard,
    path: `/projects/${projectId}`,
  }

  const imagineItem: NavItem = {
    id: 'imagine',
    label: 'Imagine',
    icon: 'imagine',
    path: `/projects/${projectId}/imagine`,
  }

  const navCategories: NavCategory[] = [
    {
      label: 'Connect',
      items: [
        {
          id: 'apps',
          label: 'Apps',
          icon: Plug,
          path: `/projects/${projectId}/apps`,
        },
        {
          id: 'api-keys',
          label: 'API Keys',
          icon: Key,
          path: `/projects/${projectId}/api-keys`,
        },
      ],
    },
    {
      label: 'Build',
      items: [
        {
          id: 'auth',
          label: 'Auth',
          icon: Users,
          path: `/projects/${projectId}/auth`,
        },
        {
          id: 'databases',
          label: 'Databases',
          icon: Database,
          path: `/projects/${projectId}/databases`,
        },
        {
          id: 'storage',
          label: 'Storage',
          icon: HardDrive,
          path: `/projects/${projectId}/storage`,
        },
        {
          id: 'functions',
          label: 'Functions',
          icon: Zap,
          path: `/projects/${projectId}/functions`,
        },
        {
          id: 'messaging',
          label: 'Messaging',
          icon: MessageSquare,
          path: `/projects/${projectId}/messaging`,
        },
      ],
    },
    {
      label: 'Deploy',
      items: [
        {
          id: 'sites',
          label: 'Sites',
          icon: Globe,
          path: `/projects/${projectId}/sites`,
        },
        {
          id: 'stores',
          label: 'Stores',
          icon: Store,
          path: `/projects/${projectId}/stores`,
          comingSoon: true,
        },
      ],
    },
    {
      label: 'Observe',
      items: [
        {
          id: 'activity',
          label: 'Activity',
          icon: Activity,
          path: `/projects/${projectId}/activity`,
        },
        {
          id: 'realtime',
          label: 'Realtime',
          icon: Radio,
          path: `/projects/${projectId}/realtime`,
        },
        {
          id: 'logs',
          label: 'Logs',
          icon: FileText,
          path: `/projects/${projectId}/logs`,
          comingSoon: true,
        },
        {
          id: 'usage',
          label: 'Usage',
          icon: BarChart3,
          path: `/projects/${projectId}/usage`,
        },
        {
          id: 'analytics',
          label: 'Analytics',
          icon: BarChart2,
          path: `/projects/${projectId}/analytics`,
        },
        {
          id: 'errors',
          label: 'Errors',
          icon: AlertTriangle,
          path: `/projects/${projectId}/errors`,
          comingSoon: true,
        },
      ],
    },
    {
      label: 'Protect',
      items: [
        {
          id: 'firewall',
          label: 'Firewall',
          icon: Shield,
          path: `/projects/${projectId}/firewall`,
          comingSoon: true,
        },
        {
          id: 'reports',
          label: 'Reports',
          icon: FileWarning,
          path: `/projects/${projectId}/reports`,
          comingSoon: true,
        },
      ],
    },
  ]

  const settingsItem: NavItem = {
    id: 'settings',
    label: 'Settings',
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
  const [collapsed, setCollapsed] = useState(false)
  const navRef = useRef<HTMLElement>(null)

  const { overviewItem, navCategories, settingsItem } = getNavItems(projectId)

  // Handle keyboard navigation within sidebar
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

  const renderNavItem = (item: NavItem, isMobile = false) => {
    const isActive = activeSection === item.id
    const isImagineIcon = item.icon === 'imagine'

    // Render the appropriate icon
    const renderIcon = () => {
      if (isImagineIcon) {
        return (
          <img
            src="/imagine-icon.svg"
            alt=""
            className={cn(
              'h-4 w-4 shrink-0 brightness-0 dark:brightness-100',
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
            'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium',
            'cursor-not-allowed text-muted-foreground/50',
            collapsed && !isMobile && 'justify-center px-0',
            isMobile && 'gap-3 px-3 py-2.5 text-[14px]',
          )}
        >
          <Icon
            className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
          />
          {(!collapsed || isMobile) && (
            <span className="flex-1 text-left">{item.label}</span>
          )}
          {(!collapsed || isMobile) && (
            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
              Soon
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
              <span className="ml-1 text-muted-foreground">(Coming Soon)</span>
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
        onClick={isMobile ? onMobileClose : undefined}
        className={cn(
          'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors duration-150',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
          isActive
            ? 'bg-accent text-foreground'
            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
          collapsed && !isMobile && 'justify-center px-0',
          isMobile && 'gap-3 px-3 py-2.5 text-[14px]',
        )}
      >
        {renderIcon()}
        {(!collapsed || isMobile) && (
          <span className="flex-1 text-left">{item.label}</span>
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
          className={cn(
            'mb-1.5 px-2.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60',
            isMobile && 'px-3',
          )}
        >
          {category.label}
        </p>
      )}
      {category.items.map((item) => renderNavItem(item, isMobile))}
    </div>
  )

  return (
    <TooltipProvider>
      {/* Desktop Sidebar */}
      <aside
        className={cn(
          'relative hidden flex-col border-r border-border bg-background transition-all duration-200 lg:flex',
          'h-full flex-shrink-0',
          collapsed ? 'w-[60px]' : 'w-[220px]',
          className,
        )}
      >
        {/* Collapse Toggle - positioned on the border */}
        <button
          onClick={() => setCollapsed(!collapsed)}
          className="absolute right-0 top-1/2 z-10 flex h-6 w-6 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
          />
        </button>

        {/* Main Navigation */}
        <nav
          ref={navRef}
          className="flex-1 space-y-6 overflow-y-auto px-3 py-4"
          onKeyDown={handleKeyDown}
          role="navigation"
          aria-label="Main navigation"
        >
          {/* Onboarding Card */}
          <OnboardingCard projectId={projectId} collapsed={collapsed} />

          {/* Overview */}
          <div className="space-y-0.5">{renderNavItem(overviewItem)}</div>

          {/* Connect category */}
          {renderCategory(navCategories[0])}

          {/* Remaining Categories (Build, Deploy, Observe, Protect) */}
          {navCategories.slice(1).map((category) => renderCategory(category))}
        </nav>

        {/* Settings */}
        <div className="flex h-[54px] w-full items-center border-t border-border px-3">
          <div className="w-full">
            {renderNavItem(settingsItem)}
          </div>
        </div>
      </aside>

      {/* Mobile Sidebar */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-[280px] flex-col border-r border-border bg-background transition-transform duration-300',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        role="dialog"
        aria-modal="true"
        aria-label="Mobile navigation"
      >
        {/* Mobile Header */}
        <div className="flex h-14 items-center justify-between px-4">
          <div className="flex items-center gap-2.5">
            <img
              src="https://appwrite.io/images/logos/logo.svg"
              alt="Appwrite"
              className="h-6 w-6"
            />
          </div>
          <button
            onClick={onMobileClose}
            className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close navigation"
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
          aria-label="Mobile navigation"
        >
          {/* Onboarding Card */}
          <OnboardingCard projectId={projectId} collapsed={false} />

          {/* Overview */}
          <div className="space-y-0.5">{renderNavItem(overviewItem, true)}</div>

          {/* Connect category */}
          {renderCategory(navCategories[0], true)}

          {/* Remaining Categories (Build, Deploy, Observe, Protect) */}
          {navCategories
            .slice(1)
            .map((category) => renderCategory(category, true))}
        </nav>

        {/* Settings */}
        <div className="border-t border-border px-4 py-3">
          {renderNavItem(settingsItem, true)}
        </div>
      </aside>
    </TooltipProvider>
  )
}

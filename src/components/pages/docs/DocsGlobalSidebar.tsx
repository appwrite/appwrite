import { useState, type ComponentType, useEffect } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import {
  ArrowUpDown,
  ArrowUpRight,
  ArrowLeftRight,
  BarChart2,
  BookOpen,
  BotMessageSquare,
  Boxes,
  Building2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Clock,
  Code,
  Cog,
  Command,
  Database,
  FileText,
  Folder,
  Globe,
  Home,
  Key,
  Layers,
  LayoutGrid,
  Link2,
  Play,
  Puzzle,
  Radio,
  RefreshCw,
  Send,
  Server,
  Share2,
  Shield,
  Sparkles,
  Terminal,
  Type,
  UserCircle,
  Users,
  X,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { GraphqlIcon } from '@/components/global/shared/GraphqlIcon'
import { OAuthIcon } from '@/components/global/shared/OAuthIcon'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'
import { DOCS_NAV_ACTIVE_BG_CLASS, DOCS_NAV_SCROLL_CLASS } from '@/lib/docs/nav-styles'
import { isAgentDocsPathname } from '@/lib/docs/agent-docs-feature'
import { isFirewallDocsPathname } from '@/lib/docs/firewall-docs-feature'
import { isPartnersDocsPathname } from '@/lib/docs/partners-docs-feature'
import { isDocsProductNavNew } from '@/lib/products/new-badge'
import { ProductNewBadge } from '@/components/global/shared/ProductNewBadge'
import {
  getDocsAudienceFromPathname,
  getDocsGlobalNav,
  isDocsNavGroup,
} from '@/lib/docs/navigation'
import type { DocsNavLink, DocsNavTree } from '@/lib/docs/types'
import { getBlogPageUrl, getDocsPageUrl, getMarketingPageUrl, isBlogPageExternal, isDocsPageExternal, isMarketingPageExternal, parseBlogPagePath, parseDocsPagePath } from '@/lib/marketing/urls'
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  OFFCANVAS_START_CLOSED,
  SIDEBAR_EDGE_TOGGLE_OVERFLOW,
} from '@/lib/layout/offcanvas-classes'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  analyticsAttrs,
  getDocsNavAnalyticsAction,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { DocsRouteLink } from './DocsRouteLink'
import { DocsAudienceSwitcher } from './DocsAudienceSwitcher'

/** Global nav group labels → curated section actions (children inherit). */
const DOCS_NAV_GROUP_ACTIONS: Record<string, AnalyticsActionId> = {
  Products: 'docs-nav-products',
  APIS: 'docs-nav-apis',
  Tooling: 'docs-nav-tooling',
  Advanced: 'docs-nav-advanced',
}

const DOCS_MENU_ICON_STROKE = 1.25

const ICON_MAP: Record<string, LucideIcon> = {
  home: Home,
  play: Play,
  'book-open': BookOpen,
  cog: Cog,
  'document-text': FileText,
  clock: Clock,
  puzzle: Puzzle,
  code: Code,
  'user-group': Users,
  building: Building2,
  boxes: Boxes,
  'layout-grid': LayoutGrid,
  database: Database,
  folder: Folder,
  zap: Zap,
  send: Send,
  globe: Globe,
  link: Link2,
  key: Key,
  'user-circle': UserCircle,
  sparkles: Sparkles,
  bot: BotMessageSquare,
  terminal: Terminal,
  share: Share2,
  shield: Shield,
  server: Server,
  rest: ArrowUpDown,
  'arrow-start-right': ArrowLeftRight,
  'bar-chart-2': BarChart2,
  command: Command,
  text: Type,
  platform: Layers,
  refresh: RefreshCw,
  radio: Radio,
}

type DocsCustomNavIconProps = {
  className?: string
  isActive?: boolean
}

function DocsTerraformNavIcon({ className, isActive }: DocsCustomNavIconProps) {
  return (
    <TerraformIcon
      variant="nav"
      className={cn(isActive && 'opacity-100', className)}
    />
  )
}

function DocsGraphqlNavIcon({ className, isActive }: DocsCustomNavIconProps) {
  return (
    <GraphqlIcon
      variant="nav"
      className={cn(isActive && 'opacity-100', className)}
    />
  )
}

function DocsOAuthNavIcon({ className, isActive }: DocsCustomNavIconProps) {
  return (
    <OAuthIcon
      variant="nav"
      className={cn(isActive && 'opacity-100', className)}
    />
  )
}

const CUSTOM_ICON_MAP: Record<string, ComponentType<DocsCustomNavIconProps>> = {
  terraform: DocsTerraformNavIcon,
  graphql: DocsGraphqlNavIcon,
  oauth: DocsOAuthNavIcon,
}

function isDocsNavActive(
  href: string,
  pathname: string,
  isParent = false,
): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (href === '/docs') return normalized === '/docs'
  if (href.startsWith('http')) return false
  if (isParent) {
    return normalized === href || normalized.startsWith(`${href}/`)
  }
  return normalized === href
}

function DocsGlobalNavItem({
  item,
  pathname,
  collapsed,
  isMobile = false,
  onNavigate,
  marketingEnabled,
  sectionAnalytics,
}: {
  item: DocsNavLink
  pathname: string
  collapsed: boolean
  isMobile?: boolean
  onNavigate?: () => void
  marketingEnabled: boolean
  sectionAnalytics?: AnalyticsActionId
}) {
  const blogPath = parseBlogPagePath(item.href)
  const docsPath = parseDocsPagePath(item.href)
  const isChangelogPath =
    item.href === '/changelog' || item.href.startsWith('/changelog/')
  const resolvedHref = docsPath
    ? getDocsPageUrl(docsPath, marketingEnabled)
    : blogPath
      ? getBlogPageUrl(blogPath, marketingEnabled)
      : isChangelogPath
        ? getMarketingPageUrl('/changelog', marketingEnabled)
        : item.href
  const isActive = isDocsNavActive(resolvedHref, pathname, item.isParent)
  const CustomIcon = item.icon ? CUSTOM_ICON_MAP[item.icon] : null
  const Icon = item.icon && !CustomIcon ? ICON_MAP[item.icon] : null
  const external =
    (docsPath
      ? isDocsPageExternal(marketingEnabled)
      : blogPath
        ? isBlogPageExternal(marketingEnabled)
        : isChangelogPath
          ? isMarketingPageExternal(marketingEnabled)
          : resolvedHref.startsWith('http')) ||
    item.openInNewTab
  const navAnalytics =
    getDocsNavAnalyticsAction(item.href) ?? sectionAnalytics
  const analytics = navAnalytics ? analyticsAttrs(navAnalytics) : undefined

  const showNewBadge = Boolean(item.new) || isDocsProductNavNew(item.href)
  const hasTrailing = (!collapsed || isMobile) && (external || showNewBadge)

  const className = cn(
    'rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors duration-150',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
    isActive
      ? cn(DOCS_NAV_ACTIVE_BG_CLASS, 'text-foreground')
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
    collapsed && !isMobile
      ? SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS
      : hasTrailing
        ? SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS
        : SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
    isMobile && 'gap-x-3 px-3 py-2.5 text-[14px]',
  )

  const content = (
    <>
      {CustomIcon ? (
        <CustomIcon
          isActive={isActive}
          className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
        />
      ) : Icon ? (
        <Icon
          className={cn('h-4 w-4 shrink-0', isMobile && 'h-[18px] w-[18px]')}
          strokeWidth={DOCS_MENU_ICON_STROKE}
        />
      ) : null}
      {(!collapsed || isMobile) && (
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>{item.label}</span>
      )}
      {(!collapsed || isMobile) && external ? (
        <ArrowUpRight
          className="size-3 shrink-0 text-muted-foreground/50"
          strokeWidth={DOCS_MENU_ICON_STROKE}
          aria-hidden
        />
      ) : null}
      {(!collapsed || isMobile) && showNewBadge ? (
        <ProductNewBadge label="New" />
      ) : null}
    </>
  )

  const link = external ? (
    <a
      href={resolvedHref}
      target="_blank"
      rel="noopener noreferrer"
      onClick={onNavigate}
      className={className}
      aria-label={`${item.label} (opens in new tab)`}
      {...analytics}
    >
      {content}
    </a>
  ) : (
    <DocsRouteLink
      href={resolvedHref}
      onClick={onNavigate}
      className={className}
      {...analytics}
    >
      {content}
    </DocsRouteLink>
  )

  if (collapsed && !isMobile && (Icon || CustomIcon)) {
    return (
      <Tooltip delayDuration={0}>
        <TooltipTrigger asChild>{link}</TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          <p>
            {item.label}
            {external ? ' (opens in new tab)' : ''}
          </p>
        </TooltipContent>
      </Tooltip>
    )
  }

  return link
}

function DocsGlobalNavCategory({
  label,
  items,
  pathname,
  collapsed,
  isMobile = false,
  collapsible = false,
  initiallyCollapsed = false,
  onNavigate,
  marketingEnabled,
}: {
  label?: string
  items: DocsNavLink[]
  pathname: string
  collapsed: boolean
  isMobile?: boolean
  collapsible?: boolean
  initiallyCollapsed?: boolean
  onNavigate?: () => void
  marketingEnabled: boolean
}) {
  const [open, setOpen] = useState(!(initiallyCollapsed ?? false))
  const sectionAnalytics = label ? DOCS_NAV_GROUP_ACTIONS[label] : undefined

  const itemList = (
    <div className="space-y-0.5">
      {items.map((item) => (
        <DocsGlobalNavItem
          key={item.href}
          item={item}
          pathname={pathname}
          collapsed={collapsed}
          isMobile={isMobile}
          onNavigate={onNavigate}
          marketingEnabled={marketingEnabled}
          sectionAnalytics={sectionAnalytics}
        />
      ))}
    </div>
  )

  if (!label) return itemList

  if (collapsible && (!collapsed || isMobile)) {
    return (
      <Collapsible open={open} onOpenChange={setOpen}>
        <CollapsibleTrigger
          className={cn(
            'mb-1.5 flex w-full cursor-pointer items-center gap-1 rounded-md px-2.5 py-1 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60 transition-colors hover:bg-accent/50 hover:text-muted-foreground',
            isMobile && 'px-3',
          )}
          aria-expanded={open}
        >
          <span className="flex-1 text-start">{label}</span>
          {open ? (
            <ChevronDown
              className="size-3.5 shrink-0 text-muted-foreground/50"
              strokeWidth={DOCS_MENU_ICON_STROKE}
            />
          ) : (
            <ChevronRight
              className="size-3.5 shrink-0 text-muted-foreground/50"
              strokeWidth={DOCS_MENU_ICON_STROKE}
            />
          )}
        </CollapsibleTrigger>
        <CollapsibleContent>{itemList}</CollapsibleContent>
      </Collapsible>
    )
  }

  return (
    <div className="space-y-0.5">
      {(!collapsed || isMobile) && (
        <p
          className={cn(
            'mb-1.5 px-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60',
            isMobile && 'px-3',
          )}
        >
          {label}
        </p>
      )}
      {itemList}
    </div>
  )
}

function DocsGlobalNavTree({
  navigation,
  pathname,
  collapsed,
  isMobile = false,
  onNavigate,
  marketingEnabled,
}: {
  navigation: DocsNavTree
  pathname: string
  collapsed: boolean
  isMobile?: boolean
  onNavigate?: () => void
  marketingEnabled: boolean
}) {
  return (
    <div className="space-y-6">
      {navigation.map((entry, index) =>
        isDocsNavGroup(entry) ? (
          <DocsGlobalNavCategory
            key={entry.label ?? index}
            label={entry.label}
            items={entry.items}
            pathname={pathname}
            collapsed={collapsed}
            isMobile={isMobile}
            collapsible={entry.collapsible}
            initiallyCollapsed={entry.initiallyCollapsed}
            onNavigate={onNavigate}
            marketingEnabled={marketingEnabled}
          />
        ) : (
          <DocsGlobalNavItem
            key={entry.href}
            item={entry}
            pathname={pathname}
            collapsed={collapsed}
            isMobile={isMobile}
            onNavigate={onNavigate}
            marketingEnabled={marketingEnabled}
          />
        ),
      )}
    </div>
  )
}

type DocsGlobalSidebarProps = {
  mobileOpen: boolean
  onMobileClose: () => void
}

export function DocsGlobalSidebar({
  mobileOpen,
  onMobileClose,
}: DocsGlobalSidebarProps) {
  const location = useLocation()
  const navigate = useNavigate()
  const pathname = location.pathname
  const [collapsed, setCollapsed] = useState(false)
  const { features } = useConsoleProfile()
  const marketingEnabled = features.marketing
  const audience = getDocsAudienceFromPathname(pathname)
  const globalNav = getDocsGlobalNav(audience)
  const [hasMounted, setHasMounted] = useState(false)

  useEffect(() => {
    setHasMounted(true)
  }, [])

  useEffect(() => {
    // Wait until after mount so localStorage debug overrides are applied before
    // we decide to bounce feature-gated docs sections.
    if (!hasMounted) return
    if (!features.partnersDocs && isPartnersDocsPathname(pathname)) {
      navigate({ to: '/docs', replace: true })
      return
    }
    if (!features.firewall && isFirewallDocsPathname(pathname)) {
      navigate({ to: '/docs', replace: true })
      return
    }
    if (!features.agent && isAgentDocsPathname(pathname)) {
      navigate({ to: '/docs', replace: true })
    }
  }, [
    features.agent,
    features.firewall,
    features.partnersDocs,
    hasMounted,
    navigate,
    pathname,
  ])

  return (
    <TooltipProvider>
      <div
        className={cn(
          'relative z-20 hidden h-full flex-shrink-0 @[1024px]:block',
          'transition-[width] duration-150 ease-out',
          collapsed ? 'w-[60px]' : 'w-[220px]',
        )}
      >
        <aside
          className={cn(
            'flex h-full w-full flex-col overflow-hidden border-e border-border bg-background',
            '[transform:translateZ(0)] [backface-visibility:hidden]',
          )}
        >
          <nav
            className={cn('flex-1 space-y-6 overflow-y-auto px-3 py-4', DOCS_NAV_SCROLL_CLASS)}
            role="navigation"
            aria-label="Docs navigation"
          >
            <DocsAudienceSwitcher pathname={pathname} collapsed={collapsed} />
            <DocsGlobalNavTree
              navigation={globalNav}
              pathname={pathname}
              collapsed={collapsed}
              marketingEnabled={marketingEnabled}
            />
          </nav>
        </aside>

        <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          {...analyticsAttrs('docs-sidebar-collapse')}
          className={cn(
            'absolute end-0 top-1/2 z-10 flex h-6 w-6 shrink-0 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            SIDEBAR_EDGE_TOGGLE_OVERFLOW,
          )}
          aria-label={collapsed ? 'Expand docs navigation' : 'Collapse docs navigation'}
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
            strokeWidth={DOCS_MENU_ICON_STROKE}
          />
        </button>
      </div>

      <aside
        className={cn(
          'fixed start-0 top-0 z-[130] flex h-[100dvh] max-h-[100dvh] w-[280px] flex-col overflow-hidden border-e border-border bg-background',
          'transition-transform duration-200 ease-out [backface-visibility:hidden]',
          mobileOpen ? 'translate-x-0' : OFFCANVAS_START_CLOSED,
          '@[1024px]:hidden',
        )}
        role="dialog"
        aria-modal="true"
        aria-label="Docs navigation"
        inert={!mobileOpen ? true : undefined}
      >
        <div className="flex h-14 items-center justify-between px-4">
          <p className="text-[14px] font-semibold text-foreground">Documentation</p>
          <button
            type="button"
            onClick={onMobileClose}
            className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close navigation"
          >
            <X className="h-5 w-5" strokeWidth={DOCS_MENU_ICON_STROKE} />
          </button>
        </div>

        <nav
          className={cn('flex-1 space-y-6 overflow-y-auto px-4 py-2', DOCS_NAV_SCROLL_CLASS)}
          role="navigation"
          aria-label="Mobile docs navigation"
        >
          <DocsAudienceSwitcher
            pathname={pathname}
            isMobile
            onNavigate={onMobileClose}
          />
          <DocsGlobalNavTree
            navigation={globalNav}
            pathname={pathname}
            collapsed={false}
            isMobile
            onNavigate={onMobileClose}
            marketingEnabled={marketingEnabled}
          />
        </nav>
      </aside>
    </TooltipProvider>
  )
}

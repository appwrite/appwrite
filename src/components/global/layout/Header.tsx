import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { cn } from '@/lib/utils'
import { formatDateMonthYear } from '@/lib/date-utils'
import { Link } from '@tanstack/react-router'
import {
  Search,
  MessageSquare,
  ChevronDown,
  LogOut,
  User,
  Menu,
  Copy,
  Check,
  Bot,
  Shield,
  Plus,
  Database,
  Users,
  Folder,
  FolderOpen,
  Zap,
  Globe,
  Building2,
  FolderPlus,
  Plug2,
  ArrowUpCircle,
  ArrowLeft,
  DatabaseZap,
  ShieldAlert,
  Sparkles,
  Home,
  LayoutDashboard,
  BookOpen,
  Clock,
  ExternalLink,
} from 'lucide-react'
import {
  useAuth,
  isOptionalAuthPage,
} from '@/components/global/auth/RequireAuth'
import { getConsoleAccountUnauthenticatedError } from '@/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  getConsoleAccountFromCache,
  getConsoleAccountSync,
  isConsoleAccountQuerySettled,
} from '@/lib/react-query/hooks/auth'
import { resolvePostAuthRedirect } from '@/lib/post-auth-navigation'
import { ProjectSelector } from '@/components/pages/projects/$projectId/shared/ProjectSelector'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { useProject, useOrganizationScopes } from '@/lib/react-query/hooks'
import {
  canShowConnectSection,
  canCreateProject,
  canCreateDatabase,
  canCreateUser,
  canCreateBucket,
  canCreateFunction,
  canCreateSite,
  canWriteTopics,
  canShowOrgDomainsTab,
} from '@/lib/console-access-checks'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import { useKeyboardShortcutsContext } from '@/components/global/providers/KeyboardShortcuts'
import { ThemeToggle } from '@/components/global/shared/ThemeToggle'
import { SupportPopover } from '@/components/global/shared/SupportPopover'
import { FeedbackPopover } from '@/components/global/shared/FeedbackPopover'
import { NotificationCenterPopover } from '@/components/global/shared/NotificationCenterPopover'
import { useAIChat } from '@/components/global/providers/AIChat'
import { Button } from '@/components/ui/button'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { ImpersonateConsoleUserPopover } from '@/components/global/shared/ImpersonateConsoleUserPopover'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { openCreateOrganizationFlow } from '@/lib/open-create-organization-flow'
import { useTheme } from 'next-themes'
import { getConsoleHeaderLogoClass } from '@/lib/html-theme'
import { ConsoleHeaderLogo } from '@/components/global/shared/ConsoleHeaderLogo'
import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { resolveInitHeaderNavCta } from '@/lib/init/events'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { useChangelogNavBadge } from '@/hooks/use-changelog-nav-badge'
import {
  getBlogPageUrl,
  getMarketingPageUrl,
  isMarketingPageExternal,
} from '@/lib/marketing/urls'
import {
  isMarketingProductsNavItem,
  MarketingProductsMobileNav,
  MarketingProductsNavPopover,
} from '@/components/pages/marketing/MarketingProductsNavMenu'
import { MarketingGitHubStarsLink } from '@/components/pages/marketing/MarketingGitHubStarsLink'
import { useI18n } from '@/lib/i18n'

type MarketingHeaderNavItem = {
  label: string
  href: string
  menu?: 'products'
  hasMenuIndicator?: boolean
}

function getDefaultMarketingHeaderNav(
  copy: ReturnType<typeof useI18n>['catalog']['app']['header']['marketingNav'],
): readonly MarketingHeaderNavItem[] {
  return [
    {
      label: copy.products,
      href: '/products/auth',
      menu: 'products',
    },
    {
      label: copy.docs,
      href: '/docs',
    },
    {
      label: copy.pricing,
      href: '/pricing',
    },
    {
      label: copy.enterprise,
      href: '/enterprise',
    },
    {
      label: copy.customers,
      href: '/blog/category/customer-stories',
    },
    {
      label: copy.blog,
      href: '/blog',
    },
    {
      label: copy.changelog,
      href: '/changelog',
    },
  ] as const
}

function getMarketingNavAnalyticsAction(
  href: string,
): AnalyticsActionId | undefined {
  if (href === '/docs') return 'marketing-nav-docs'
  if (href === '/pricing') return 'marketing-nav-pricing'
  if (href === '/enterprise') return 'marketing-nav-enterprise'
  if (href === '/blog/category/customer-stories') return 'marketing-nav-customers'
  if (href === '/blog') return 'marketing-nav-blog'
  if (href === '/changelog') return 'marketing-nav-changelog'
  return undefined
}

const ACCOUNT_MENU_ITEM_CLASS =
  'flex w-full cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-start text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground'

function MarketingNavLabel({
  label,
  showNewIndicator,
}: {
  label: string
  showNewIndicator?: boolean
}) {
  return (
    <span className="relative">
      {label}
      {showNewIndicator ? (
        <span
          className="absolute -end-1.5 -top-1 size-1.5 rounded-full bg-[var(--brand-cta)]"
          aria-hidden
        />
      ) : null}
    </span>
  )
}

function MarketingNavLink({
  item,
  showChangelogBadge,
  changelogAriaLabel,
  className,
}: {
  item: MarketingHeaderNavItem
  showChangelogBadge: boolean
  changelogAriaLabel: string
  className?: string
}) {
  const navAnalytics = getMarketingNavAnalyticsAction(item.href)
  return (
    <a
      href={item.href}
      className={cn(
        'link-unstyled inline-flex h-9 items-center gap-1 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
        className,
      )}
      {...(navAnalytics ? analyticsAttrs(navAnalytics) : {})}
      {...(item.href === '/changelog' && showChangelogBadge
        ? { 'aria-label': changelogAriaLabel }
        : {})}
    >
      <MarketingNavLabel
        label={item.label}
        showNewIndicator={item.href === '/changelog' && showChangelogBadge}
      />
    </a>
  )
}

function MarketingMobileNavLink({
  item,
  showChangelogBadge,
  changelogAriaLabel,
}: {
  item: MarketingHeaderNavItem
  showChangelogBadge: boolean
  changelogAriaLabel: string
}) {
  const navAnalytics = getMarketingNavAnalyticsAction(item.href)
  return (
    <SheetClose asChild>
      <a
        href={item.href}
        className="link-unstyled flex h-10 w-full items-center justify-start rounded-md px-3 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        {...(navAnalytics ? analyticsAttrs(navAnalytics) : {})}
        {...(item.href === '/changelog' && showChangelogBadge
          ? { 'aria-label': changelogAriaLabel }
          : {})}
      >
        <MarketingNavLabel
          label={item.label}
          showNewIndicator={item.href === '/changelog' && showChangelogBadge}
        />
      </a>
    </SheetClose>
  )
}

interface ConsoleHeaderProps {
  onMenuClick?: () => void
  className?: string
  projectId?: string
  onCommandCenterOpen?: () => void
  onCreateOrganization?: () => void
  marketingNav?: boolean | readonly MarketingHeaderNavItem[]
  /** Shown after the wordmark, e.g. "Docs" renders as "| Docs". */
  headerTitleSuffix?: string
  /** Wide search field centered in the header instead of marketing links. */
  centerSearch?: boolean
  /** Placeholder for search when {@link centerSearch} is enabled. */
  centerSearchPlaceholder?: string
  /** When true, search is hidden (e.g. when native app bar is shown above) */
  hideSearch?: boolean
}

export function ConsoleHeader({
  onMenuClick,
  className,
  projectId,
  onCommandCenterOpen,
  onCreateOrganization,
  marketingNav,
  headerTitleSuffix,
  centerSearch = false,
  centerSearchPlaceholder,
  hideSearch = false,
}: ConsoleHeaderProps) {
  const { openCommandCenter: contextOpenCommandCenter } =
    useKeyboardShortcutsContext()
  const { toggleChat } = useAIChat()
  const queryClient = useQueryClient()
  const {
    account,
    signOut,
    isAuthenticated,
    isFetched: isAuthFetched,
  } = useAuth()
  const headerAccount =
    (account as Models.User | undefined) ??
    (getConsoleAccountFromCache(queryClient) as Models.User | undefined) ??
    getConsoleAccountSync()
  const operatorAccount = headerAccount as OperatorAccount | undefined
  const showAdminSection = isOperatorAccount(operatorAccount)
  const location = useLocation()
  const navigate = useNavigate()
  const params = useParams({ strict: false })
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const projectConnectDialog = useProjectConnectDialog()
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

  // Use prop if provided, otherwise fall back to context
  const openCommandCenter = onCommandCenterOpen || contextOpenCommandCenter

  // Check if we're on the org overview (no projectId)
  const isOrgOverview = !projectId

  // Fetch current project to get teamId when in project context
  const { project } = useProject(projectId)
  const orgIdFromRoute = params?.orgId as string | undefined
  const orgId = projectId
    ? (project?.teamId ?? undefined)
    : (orgIdFromRoute ??
      (headerAccount?.prefs?.organization as string | undefined))
  const { features } = useConsoleProfile()
  const { catalog } = useI18n()
  const headerCopy = catalog.app.header
  const resolvedCenterSearchPlaceholder =
    centerSearchPlaceholder ?? headerCopy.centerSearchPlaceholder
  const supportsMultiTenancy = features.multiTenancy
  const overrides = useDebugOverrides()
  const { access } = useOrganizationScopes(orgId ?? project?.teamId)
  const defaultMarketingHeaderNav = getDefaultMarketingHeaderNav(
    headerCopy.marketingNav,
  )
  const marketingNavItems = (
    marketingNav === true
      ? defaultMarketingHeaderNav
      : marketingNav
        ? marketingNav
        : []
  ).map((item) => {
    if (item.href === '/blog') {
      return { ...item, href: getBlogPageUrl('/blog', features.marketing) }
    }
    if (item.href === '/blog/category/customer-stories') {
      return {
        ...item,
        href: getBlogPageUrl('/blog/category/customer-stories', features.marketing),
      }
    }
    if (item.href === '/docs') {
      return { ...item, href: getMarketingPageUrl('/docs', features.marketing) }
    }
    if (item.href === '/changelog') {
      return { ...item, href: getMarketingPageUrl('/changelog', features.marketing) }
    }
    return item
  })
  const showMarketingNav = marketingNavItems.length > 0
  const showAIAssistant = overrides.showAIAssistant && !showMarketingNav
  const showConnectAndCreate = canShowConnectSection(access, features)
  const canCreateProjectFlag = canCreateProject(access, features)
  const canCreateDatabaseFlag = canCreateDatabase(access, features)
  const canCreateUserFlag = canCreateUser(access, features)
  const canCreateBucketFlag = canCreateBucket(access, features)
  const canCreateFunctionFlag = canCreateFunction(access, features)
  const canCreateSiteFlag = canCreateSite(access, features)
  const canCreateTopicFlag = canWriteTopics(access, features)

  // Fetch organization plan to check if upgrade button should be shown
  const { plan: organizationPlan, isFetched: isPlanFetched } =
    useOrganizationPlan(orgId)
  const selfService = organizationPlan?.selfService !== false

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  // Get user display name (prefer name, fallback to email)
  const displayName =
    headerAccount?.name ||
    headerAccount?.email ||
    headerCopy.accountMenu.user
  const userEmail = headerAccount?.email || ''
  const accountId = headerAccount?.$id || ''

  // Format member since date using consistent formatting
  // Account registration can be in various formats (Unix timestamp, ISO string, etc.)
  const memberSince = formatDateMonthYear(
    headerAccount?.registration ||
      headerAccount?.createdAt ||
      headerAccount?.$createdAt,
  )

  // Account status (active if account exists)
  const accountStatus = headerAccount
    ? headerCopy.accountMenu.active
    : headerCopy.accountMenu.inactive

  // Check if 2FA is enabled
  // Appwrite account object may have mfa or twoFactorAuthenticatorEnabled property
  const is2FAEnabled =
    headerAccount?.mfa === true ||
    headerAccount?.twoFactorAuthenticatorEnabled === true

  const hasSidebar = !isOrgOverview
  const isAccountScope = location.pathname.startsWith('/account')
  const isInitScope = features.init && location.pathname === '/init'
  const initHeaderNavCta = isInitScope
    ? resolveInitHeaderNavCta({ mockCurrentDay: overrides.mockInitCurrentDay })
    : null
  const isOptionalAuth = isOptionalAuthPage(location.pathname)
  const optionalAuthResolved =
    isAuthFetched ||
    isConsoleAccountQuerySettled(queryClient) ||
    !!headerAccount ||
    getConsoleAccountUnauthenticatedError(getConsoleAccountQueryRevision()) !==
      undefined
  const optionalAuthPending = isOptionalAuth && !optionalAuthResolved
  const headerAuthenticated = isAuthenticated || !!headerAccount
  const showGuestHeader =
    isOptionalAuth && optionalAuthResolved && !headerAuthenticated
  const authRedirect = resolvePostAuthRedirect(location.pathname)
  const showMarketingLinks = showMarketingNav && !centerSearch
  // Only show upgrade when current plan cost is 0 (free); hide when already on a paid plan.
  // Wait for plan fetch so we do not flash the button while price is still unknown.
  // Marketing layout defers the control to @[1720px] so the centered nav stays clear.
  const showUpgradeButton =
    features.billing &&
    orgId &&
    isPlanFetched &&
    selfService &&
    (organizationPlan?.price ?? 0) === 0
  const showChangelogBadge = useChangelogNavBadge()
  const showOrgDomainsLink = Boolean(orgId && canShowOrgDomainsTab(access, features))
  const docsHref = getMarketingPageUrl('/docs', features.marketing)
  const changelogHref = getMarketingPageUrl('/changelog', features.marketing)
  const homeHref = getMarketingPageUrl('/home', features.marketing)
  const marketingNavLinksExternal = isMarketingPageExternal(features.marketing)
  const showCenterSearch = centerSearch && !hideSearch
  const showRightSearch = !hideSearch && !centerSearch
  const { modKey: searchModKey } = usePlatform()
  const logoColumnWidth = showMarketingNav ? 158 : 60

  return (
    <div className="@container w-full overflow-visible">
      <header
        className={cn(
          'h-14 min-h-14 items-center gap-1 overflow-visible border-b border-border bg-background @[640px]:gap-2',
          'ps-3 pe-3 @[640px]:ps-4 @[640px]:pe-4 @[1000px]:pe-6',
          // Equal side columns keep the marketing nav centered whether the right
          // cluster is Sign in/up or search + account actions.
          showMarketingLinks
            ? 'grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]'
            : 'relative flex flex-wrap justify-between',
          !showMarketingNav && '@[1024px]:ps-0',
          className,
        )}
      >
        {/* Left: Menu + Logo (+ nav border when project) + Project Selector */}
        <div
          className={cn(
            'flex min-w-0 items-center gap-1.5 overflow-visible @[640px]:gap-2',
            showMarketingLinks ? 'justify-self-start' : 'flex-1',
          )}
        >
          {/* Mobile menu button - only show when in project context and sidebar is hidden */}
          {onMenuClick ? (
            <button
              type="button"
              onClick={onMenuClick}
              className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1024px]:hidden"
              aria-label={headerCopy.actions.openNavigation}
            >
              <Menu className="h-5 w-5" />
            </button>
          ) : null}

          {showMarketingLinks ? (
            <Sheet>
              <SheetTrigger asChild>
                <button
                  type="button"
                  className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1280px]:hidden"
                  aria-label={headerCopy.actions.openWebsiteNavigation}
                >
                  <Menu className="h-4 w-4" />
                </button>
              </SheetTrigger>
              <SheetContent side="left" className="w-[300px] p-0">
                <SheetHeader className="border-b border-border px-4 py-4">
                  <SheetTitle className="text-start">
                    <AppwriteWordmark className="h-5" aria-label="Appwrite" />
                  </SheetTitle>
                </SheetHeader>
                <nav
                  className="flex flex-col p-2 text-start"
                  aria-label={headerCopy.marketingNav.websiteNavigation}
                >
                  {marketingNavItems.map((item) =>
                    isMarketingProductsNavItem(item) ? (
                      <MarketingProductsMobileNav key={item.label} />
                    ) : (
                      <MarketingMobileNavLink
                        key={item.label}
                        item={item}
                        showChangelogBadge={showChangelogBadge}
                        changelogAriaLabel={
                          headerCopy.marketingNav.changelogNewUpdatesAria
                        }
                      />
                    ),
                  )}
                  <MarketingGitHubStarsLink mobile />
                </nav>
              </SheetContent>
            </Sheet>
          ) : null}

          {/* Logo - 60px column matches collapsed nav; never shifts; optional spacer + border continues from nav */}
          {(() => {
            const linkOrgId =
              project?.teamId ||
              (headerAccount?.prefs?.organization as string | undefined)
            const logoDestination = showMarketingNav
              ? ({ to: '/home' } as const)
              : showGuestHeader && features.init
                ? ({ to: '/init' } as const)
                : linkOrgId
                  ? ({
                      to: '/organizations/$orgId',
                      params: { orgId: linkOrgId },
                    } as const)
                  : ({ to: '/' } as const)
            const logoLink = (childClassName?: string) => (
              <Link
                {...logoDestination}
                aria-label="Appwrite"
                className={cn(
                  'group inline-flex shrink-0 items-center justify-center rounded-lg transition-transform duration-150 ease-out active:scale-[0.94] active:bg-muted/40 motion-reduce:active:scale-100 motion-reduce:active:bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                  showMarketingNav ? 'h-10 w-auto px-2' : 'size-10',
                  childClassName,
                )}
              >
                {showMarketingNav ? (
                  <AppwriteWordmark
                    className="h-5 transition-transform duration-150 ease-out group-hover:scale-[1.02]"
                    aria-label="Appwrite"
                  />
                ) : (
                  <ConsoleHeaderLogo
                    className={cn(
                      'transition-transform duration-150 ease-out group-hover:scale-[1.04]',
                      headerLogoClassName,
                    )}
                  />
                )}
              </Link>
            )

            if (showMarketingNav) {
              return (
                <div className="flex min-w-0 items-center gap-2">
                  {logoLink()}
                  {headerTitleSuffix ? (
                    <>
                      <span
                        className="shrink-0 text-[15px] text-muted-foreground/40"
                        aria-hidden
                      >
                        |
                      </span>
                      <span className="truncate text-[13px] font-medium text-muted-foreground">
                        {headerTitleSuffix}
                      </span>
                    </>
                  ) : null}
                </div>
              )
            }

            if (hasSidebar) {
              return (
                <>
                  {/* Desktop: 60px logo column, border continues from nav */}
                  <div
                    className="hidden h-14 shrink-0 items-center justify-center border-e border-border @[1024px]:flex"
                    style={{ width: logoColumnWidth }}
                  >
                    {logoLink()}
                  </div>
                  {/* Mobile */}
                  {logoLink('@[1024px]:hidden')}
                </>
              )
            }

            /* Org: same 60px column */
            return (
              <>
                <div
                  className="hidden h-14 shrink-0 items-center justify-center @[1024px]:flex"
                  style={{ width: logoColumnWidth }}
                >
                  {logoLink()}
                </div>
                {logoLink('@[1024px]:hidden')}
              </>
            )
          })()}

          {/* Account scope quick return */}
          {isAccountScope && orgId && (
            <Button
              asChild
              variant="ghost"
              size="sm"
              className="hidden h-9 shrink-0 gap-1.5 px-2.5 text-[13px] @[850px]:inline-flex"
            >
              <Link to="/organizations/$orgId" params={{ orgId }}>
                <ArrowLeft className="h-4 w-4" />
                {headerCopy.actions.backToOrganization}
              </Link>
            </Button>
          )}

          {/* Init scope exit / try CTA */}
          {initHeaderNavCta ? (
            <Button
              asChild
              variant="ghost"
              size="sm"
              className="hidden h-9 shrink-0 gap-1.5 px-2.5 text-[13px] @[850px]:inline-flex"
            >
              {initHeaderNavCta.to ? (
                <Link to={initHeaderNavCta.to}>
                  <ArrowLeft className="h-4 w-4" />
                  {initHeaderNavCta.label}
                </Link>
              ) : (
                <a
                  href={initHeaderNavCta.href}
                  target={initHeaderNavCta.external ? '_blank' : undefined}
                  rel={
                    initHeaderNavCta.external
                      ? 'noopener noreferrer'
                      : undefined
                  }
                >
                  <ArrowLeft className="h-4 w-4" />
                  {initHeaderNavCta.label}
                </a>
              )}
            </Button>
          ) : null}

          {/* Project Selector - only show when in project context */}
          {!isOrgOverview && (
            <>
              {/* Project Selector */}
              <div className="hidden min-w-0 overflow-visible @[700px]:block">
                <ProjectSelector
                  projectId={projectId}
                  className="max-w-full min-w-0"
                  onCreateOrganization={onCreateOrganization}
                />
              </div>

              {/* Connect button - only owners/developers; always visible regardless of project state */}
              {showConnectAndCreate && projectId && (
                <button
                  type="button"
                  {...analyticsAttrs('connect-project')}
                  className="flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer hidden @[700px]:flex text-[13px]"
                  onClick={() => projectConnectDialog?.openConnect()}
                >
                  <Plug2 className="h-4 w-4" />
                  {headerCopy.actions.connect}
                </button>
              )}

              {/* Create Button - only owners and developers */}
              {showConnectAndCreate && (
                <div className="hidden @[700px]:block shrink-0">
                  <DropdownMenu>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <DropdownMenuTrigger asChild>
                          <button
                            {...analyticsAttrs('header-create-menu')}
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer"
                          >
                            <Plus className="h-4 w-4" />
                          </button>
                        </DropdownMenuTrigger>
                      </TooltipTrigger>
                      <TooltipContent>
                        <p>{headerCopy.actions.create}</p>
                      </TooltipContent>
                    </Tooltip>
                    <DropdownMenuContent align="start" className="w-56">
                      {!canCreateProjectFlag ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="block">
                              <DropdownMenuItem
                                disabled
                                className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                              >
                                <FolderPlus className="h-4 w-4" />
                                <span>{headerCopy.createMenu.newProject}</span>
                              </DropdownMenuItem>
                            </span>
                          </TooltipTrigger>
                          <TooltipContent>
                            <p>
                              {headerCopy.permissions.createProjects}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <DropdownMenuItem
                          {...analyticsAttrs('create-project')}
                          onClick={() => {
                            const orgId =
                              project?.teamId ||
                              (headerAccount?.prefs?.organization as
                                | string
                                | undefined)
                            if (orgId) {
                              navigate({
                                to: '/organizations/$orgId',
                                params: { orgId },
                                search: { create: 'project' } as Record<
                                  string,
                                  unknown
                                >,
                              })
                            }
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <FolderPlus className="h-4 w-4" />
                          <span>{headerCopy.createMenu.newProject}</span>
                        </DropdownMenuItem>
                      )}
                      {supportsMultiTenancy && (
                        <DropdownMenuItem
                          {...analyticsAttrs('create-organization')}
                          onClick={() => {
                            const orgId =
                              project?.teamId ||
                              (headerAccount?.prefs?.organization as
                                | string
                                | undefined)
                            openCreateOrganizationFlow(navigate, {
                              onCreateOrganization,
                              orgId,
                            })
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <Building2 className="h-4 w-4" />
                          <span>{headerCopy.createMenu.newOrganization}</span>
                        </DropdownMenuItem>
                      )}

                      {projectId && (
                        <>
                          <DropdownMenuSeparator />
                          {/* Resources Category */}
                          <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                            {headerCopy.createMenu.buildSection}
                          </DropdownMenuLabel>
                          {!canCreateDatabaseFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <Database className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newDatabase}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createDatabases}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-database')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/databases',
                                  params: { projectId },
                                  search: { create: 'database' } as Record<
                                    string,
                                    unknown
                                  >,
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Database className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newDatabase}</span>
                            </DropdownMenuItem>
                          )}
                          {!canCreateUserFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <Users className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newUser}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createUsers}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-user')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/auth',
                                  params: { projectId },
                                  search: { create: 'user' } as Record<
                                    string,
                                    unknown
                                  >,
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Users className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newUser}</span>
                            </DropdownMenuItem>
                          )}
                          {!canCreateBucketFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <Folder className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newBucket}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createBuckets}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-bucket')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/storage/',
                                  params: { projectId },
                                  search: { create: 'bucket' } as Record<
                                    string,
                                    unknown
                                  >,
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Folder className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newBucket}</span>
                            </DropdownMenuItem>
                          )}
                          {!canCreateFunctionFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <Zap className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newFunction}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createFunctions}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-function')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/functions/create',
                                  params: { projectId },
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Zap className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newFunction}</span>
                            </DropdownMenuItem>
                          )}
                          {!canCreateTopicFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <MessageSquare className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newMessage}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createTopics}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-topic')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/messaging',
                                  params: { projectId },
                                  search: { create: 'topic' } as Record<
                                    string,
                                    unknown
                                  >,
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <MessageSquare className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newMessage}</span>
                            </DropdownMenuItem>
                          )}

                          <DropdownMenuSeparator />
                          {/* Deploy Category */}
                          <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                            {headerCopy.createMenu.deploySection}
                          </DropdownMenuLabel>
                          {!canCreateSiteFlag ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="block">
                                  <DropdownMenuItem
                                    disabled
                                    className="flex cursor-default items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground"
                                  >
                                    <Globe className="h-4 w-4" />
                                    <span>{headerCopy.createMenu.newSite}</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  {headerCopy.permissions.createSites}
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              {...analyticsAttrs('create-site')}
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/sites/create',
                                  params: { projectId },
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Globe className="h-4 w-4" />
                              <span>{headerCopy.createMenu.newSite}</span>
                            </DropdownMenuItem>
                          )}
                        </>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              )}
            </>
          )}
        </div>

        {showMarketingLinks ? (
          // Always occupy the center grid track (even when the nav is visually
          // hidden below 1280px). A `hidden` nav alone would leave the grid and
          // drop the right actions into the middle column.
          <div className="min-w-0 justify-self-center">
            <nav
              className="hidden items-center justify-center gap-1 @[1280px]:flex"
              aria-label={headerCopy.marketingNav.websiteNavigation}
            >
              {marketingNavItems.map((item) =>
                isMarketingProductsNavItem(item) ? (
                  <MarketingProductsNavPopover key={item.label} />
                ) : (
                  <MarketingNavLink
                    key={item.label}
                    item={item}
                    showChangelogBadge={showChangelogBadge}
                    changelogAriaLabel={
                      headerCopy.marketingNav.changelogNewUpdatesAria
                    }
                  />
                ),
              )}
              <MarketingGitHubStarsLink />
            </nav>
          </div>
        ) : null}

        {showCenterSearch ? (
          <div className="pointer-events-none absolute left-1/2 hidden w-full max-w-[25rem] -translate-x-1/2 px-4 @[900px]:block">
            <button
              type="button"
              {...analyticsAttrs('command-center')}
              onClick={openCommandCenter}
              className="pointer-events-auto flex h-9 w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent"
            >
              <Search className="h-3.5 w-3.5 shrink-0" />
              <span className="min-w-0 flex-1 truncate text-start">
                {resolvedCenterSearchPlaceholder}
              </span>
              {searchModKey ? (
                <span className="ms-auto flex shrink-0 items-center gap-1">
                  <span dir="ltr" className="flex items-center gap-1">
                    <kbd className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85">
                      {searchModKey}
                    </kbd>
                    <kbd className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85">
                      K
                    </kbd>
                  </span>
                </span>
              ) : null}
            </button>
          </div>
        ) : null}

        {/* Right: Actions */}
        <div
          className={cn(
            'flex min-w-0 shrink-0 items-center gap-1 @[640px]:gap-2',
            showMarketingLinks && 'justify-self-end',
          )}
        >
          {optionalAuthPending ? (
            <div
              className="flex h-9 items-center gap-1 @[640px]:gap-2"
              aria-hidden
            >
              <div className="h-9 w-[4.75rem] shrink-0 rounded-md @[640px]:w-[4.875rem]" />
              <div className="h-9 w-[4.875rem] shrink-0 rounded-md" />
            </div>
          ) : showGuestHeader ? (
            <>
              {showCenterSearch ? (
                <button
                  type="button"
                  {...analyticsAttrs('command-center')}
                  onClick={openCommandCenter}
                  className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                  aria-label={resolvedCenterSearchPlaceholder}
                >
                  <Search className="h-4 w-4" />
                </button>
              ) : null}
              <Button
                asChild
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
              >
                <Link
                  to="/sign-in"
                  search={authRedirect ? { redirect: authRedirect } : undefined}
                  {...analyticsAttrs('auth-sign-in')}
                >
                  {headerCopy.actions.signIn}
                </Link>
              </Button>
              <Button
                asChild
                size="sm"
                variant="brandCta"
                className="h-9 text-[13px]"
              >
                <Link
                  to="/sign-up"
                  search={authRedirect ? { redirect: authRedirect } : undefined}
                  {...analyticsAttrs('auth-sign-up')}
                >
                  {headerCopy.actions.signUp}
                </Link>
              </Button>
            </>
          ) : (
            <>
              {/* Search - full pill in console; compact Search+kbd on marketing so
                  both auth states keep a true-centered nav without collisions. */}
              {showRightSearch ? (
                showMarketingLinks ? (
                  <>
                    <button
                      type="button"
                      {...analyticsAttrs('command-center')}
                      onClick={openCommandCenter}
                      className="hidden h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border border-border bg-accent/50 px-2.5 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent @[700px]:flex"
                      aria-label={headerCopy.search.compactPlaceholder}
                    >
                      <Search className="h-3.5 w-3.5 shrink-0" />
                      {searchModKey ? (
                        <kbd
                          dir="ltr"
                          className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85"
                        >
                          {searchModKey}K
                        </kbd>
                      ) : null}
                    </button>
                    <button
                      type="button"
                      {...analyticsAttrs('command-center')}
                      onClick={openCommandCenter}
                      className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[700px]:hidden"
                      aria-label={headerCopy.search.compactPlaceholder}
                    >
                      <Search className="h-4 w-4" />
                    </button>
                  </>
                ) : (
                  <>
                    <button
                      type="button"
                      {...analyticsAttrs('command-center')}
                      onClick={openCommandCenter}
                      className="hidden h-9 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent @[700px]:flex"
                    >
                      <Search className="h-3.5 w-3.5 shrink-0" />
                      <span className="hidden @[850px]:inline">
                        {headerCopy.search.compactPlaceholder}
                      </span>
                      {searchModKey ? (
                        <span className="ms-2 hidden shrink-0 @[850px]:inline">
                          <kbd
                            dir="ltr"
                            className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground/85"
                          >
                            {searchModKey}K
                          </kbd>
                        </span>
                      ) : null}
                    </button>

                    <button
                      type="button"
                      {...analyticsAttrs('command-center')}
                      onClick={openCommandCenter}
                      className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[700px]:hidden"
                      aria-label={headerCopy.search.compactPlaceholder}
                    >
                      <Search className="h-4 w-4" />
                    </button>
                  </>
                )
              ) : showCenterSearch ? (
                <button
                  type="button"
                  {...analyticsAttrs('command-center')}
                  onClick={openCommandCenter}
                  className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[900px]:hidden"
                  aria-label={resolvedCenterSearchPlaceholder}
                >
                  <Search className="h-4 w-4" />
                </button>
              ) : null}

              {/* Feedback / Support - console tools; on marketing only at very wide
                  widths so they cannot crowd the centered Changelog / stars. */}
              <div
                className={cn(
                  'hidden shrink-0',
                  showMarketingLinks ? '@[1720px]:flex' : '@[800px]:flex',
                )}
              >
                <FeedbackPopover
                  source="navbar"
                  orgId={orgId}
                  projectId={projectId ?? ''}
                  billingPlanId={organizationPlan?.$id}
                />
              </div>

              <div
                className={cn(
                  'hidden shrink-0',
                  showMarketingLinks ? '@[1720px]:flex' : '@[900px]:flex',
                )}
              >
                <SupportPopover orgId={orgId} />
              </div>

              {/* Notifications */}
              <div className="flex shrink-0">
                <NotificationCenterPopover />
              </div>

              {/* Operator tools (render nothing when account is not an impersonator) */}
              <ImpersonateConsoleUserPopover />

              {/* Help/Assistant - hidden on small containers; enabled by profile or experimental override */}
              {showAIAssistant && (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={toggleChat}
                      {...analyticsAttrs('ai-assistant-open')}
                      className="hidden h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1000px]:flex"
                    >
                      <Bot className="h-4 w-4" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>{headerCopy.actions.assistant}</p>
                  </TooltipContent>
                </Tooltip>
              )}

              {/* Divider before Upgrade Button - hidden on small containers */}
              {showUpgradeButton && (
                <div
                  className={cn(
                    'mx-1 hidden h-5 w-px shrink-0 bg-border @[640px]:mx-2',
                    showMarketingLinks ? '@[1720px]:block' : '@[850px]:block',
                  )}
                />
              )}

              {/* Upgrade Button - hidden on small containers; only when plan cost is 0 */}
              {showUpgradeButton && (
                <div
                  className={cn(
                    'hidden shrink-0 rounded-md focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background',
                    showMarketingLinks ? '@[1720px]:flex' : '@[850px]:flex',
                  )}
                >
                  <div className="upgrade-button-wrapper">
                    <Button
                      asChild
                      size="sm"
                      variant="brandCta"
                      className="h-9 shrink-0 cursor-pointer gap-1.5 px-3 text-[12px] font-semibold relative z-10 rounded-[calc(0.375rem-1px)]"
                    >
                      <Link
                        to="/upgrade"
                        search={{ orgId }}
                        {...analyticsAttrs('upgrade-clicked')}
                      >
                        <ArrowUpCircle className="h-4 w-4" />
                        {headerCopy.actions.upgrade}
                      </Link>
                    </Button>
                  </div>
                </div>
              )}

              {/* Divider - hidden on small containers */}
              <div
                className={cn(
                  'mx-1 hidden h-5 w-px shrink-0 bg-border @[640px]:mx-2',
                  showMarketingLinks ? '@[1280px]:block' : '@[700px]:block',
                )}
              />

              {/* User Menu */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <button
                    {...analyticsAttrs('user-menu')}
                    className="flex h-9 shrink-0 cursor-pointer items-center gap-2 rounded-md px-2 transition-colors hover:bg-accent min-w-0"
                  >
                    <InitialsAvatar
                      name={displayName}
                      size="sm"
                      className="shrink-0"
                    />
                    <div
                      className={cn(
                        'hidden min-w-0 text-start',
                        showMarketingLinks
                          ? '@[1600px]:block'
                          : '@[800px]:block',
                      )}
                    >
                      <p className="text-[13px] font-medium text-foreground truncate">
                        {displayName}
                      </p>
                    </div>
                    <ChevronDown
                      className={cn(
                        'hidden h-3.5 w-3.5 shrink-0 text-muted-foreground',
                        showMarketingLinks
                          ? '@[1600px]:block'
                          : '@[800px]:block',
                      )}
                    />
                  </button>
                </DropdownMenuTrigger>

                <DropdownMenuContent
                  align="end"
                  className="w-64 border-border bg-popover p-1"
                >
                  <div className="px-3 py-3 text-start">
                    <p className="text-[13px] font-medium text-foreground">
                      {displayName}
                    </p>
                    {userEmail && (
                      <p className="text-[12px] text-muted-foreground">
                        {userEmail}
                      </p>
                    )}
                  </div>

                  <DropdownMenuSeparator className="my-1 bg-border" />

                  <DropdownMenuItem asChild>
                    <Link to="/account" className={ACCOUNT_MENU_ITEM_CLASS}>
                      <User className="h-4 w-4" />
                      <span>{headerCopy.accountMenu.account}</span>
                    </Link>
                  </DropdownMenuItem>

                  <DropdownMenuItem asChild>
                    <Link
                      {...(orgId
                        ? {
                            to: '/organizations/$orgId',
                            params: { orgId },
                          }
                        : { to: '/' })}
                      className={ACCOUNT_MENU_ITEM_CLASS}
                    >
                      <FolderOpen className="h-4 w-4" />
                      <span>{headerCopy.accountMenu.projects}</span>
                    </Link>
                  </DropdownMenuItem>

                  {showOrgDomainsLink && orgId ? (
                    <DropdownMenuItem asChild>
                      <Link
                        to="/organizations/$orgId/domains"
                        params={{ orgId }}
                        className={ACCOUNT_MENU_ITEM_CLASS}
                      >
                        <Globe className="h-4 w-4" />
                        <span>{headerCopy.accountMenu.domains}</span>
                      </Link>
                    </DropdownMenuItem>
                  ) : null}

                  <DropdownMenuSeparator className="my-1 bg-border" />

                  {/* Account Details */}
                  <div className="px-3 py-2 space-y-4 text-start">
                    {/* Member Since */}
                    {headerAccount?.registration && (
                      <div>
                        <p className="text-[11px] text-muted-foreground mb-1.5">
                          {headerCopy.accountMenu.memberSince}
                        </p>
                        <p className="text-[14px] text-foreground">
                          {memberSince}
                        </p>
                      </div>
                    )}

                    {/* Account Status */}
                    <div>
                      <p className="text-[11px] text-muted-foreground mb-1.5">
                        {headerCopy.accountMenu.accountStatus}
                      </p>
                      <div className="flex items-center gap-2">
                        <div className="h-2 w-2 rounded-full bg-emerald-500" />
                        <p className="text-[14px] text-foreground">
                          {accountStatus}
                        </p>
                      </div>
                    </div>

                    {features.accountMfa && (
                      <div>
                        <p className="text-[11px] text-muted-foreground mb-1.5">
                          {headerCopy.accountMenu.twoFactor}
                        </p>
                        <div className="flex items-center gap-2">
                          {is2FAEnabled ? (
                            <>
                              <Shield className="h-3.5 w-3.5 text-emerald-500" />
                              <p className="text-[14px] text-foreground">
                                {headerCopy.accountMenu.enabled}
                              </p>
                            </>
                          ) : (
                            <>
                              <Shield className="h-3.5 w-3.5 text-muted-foreground" />
                              <p className="text-[14px] text-muted-foreground">
                                {headerCopy.accountMenu.disabled}
                              </p>
                            </>
                          )}
                        </div>
                      </div>
                    )}

                    {/* Account ID */}
                    {accountId && (
                      <div>
                        <p className="text-[11px] text-muted-foreground mb-1.5">
                          {headerCopy.accountMenu.accountId}
                        </p>
                        <TooltipProvider delayDuration={0}>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <button
                                onClick={() =>
                                  copyToClipboard(accountId, 'accountId')
                                }
                                className="flex cursor-pointer items-center gap-1.5 group"
                              >
                                <p className="text-[14px] text-foreground font-mono">
                                  {accountId}
                                </p>
                                {copiedField === 'accountId' ? (
                                  <Check className="h-3.5 w-3.5 text-emerald-500" />
                                ) : (
                                  <Copy className="h-3.5 w-3.5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                                )}
                              </button>
                            </TooltipTrigger>
                            <TooltipContent side="left">
                              <p>
                                {copiedField === 'accountId'
                                  ? headerCopy.accountMenu.copied
                                  : headerCopy.accountMenu.copyAccountId}
                              </p>
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </div>
                    )}
                  </div>

                  <>
                    <DropdownMenuSeparator className="my-1 bg-border" />

                    {showMarketingNav ? (
                      <DropdownMenuItem asChild>
                        <Link
                          {...(orgId
                            ? {
                                to: '/organizations/$orgId',
                                params: { orgId },
                              }
                            : { to: '/' })}
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...analyticsAttrs('header-console')}
                        >
                          <LayoutDashboard className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.console}</span>
                        </Link>
                      </DropdownMenuItem>
                    ) : marketingNavLinksExternal ? (
                      <DropdownMenuItem asChild>
                        <a
                          href={homeHref}
                          target="_blank"
                          rel="noopener noreferrer"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...analyticsAttrs('header-home')}
                        >
                          <Home className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.home}</span>
                        </a>
                      </DropdownMenuItem>
                    ) : (
                      <DropdownMenuItem asChild>
                        <Link
                          to="/home"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...analyticsAttrs('header-home')}
                        >
                          <Home className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.home}</span>
                        </Link>
                      </DropdownMenuItem>
                    )}

                    <DropdownMenuItem asChild>
                      {marketingNavLinksExternal ? (
                        <a
                          href={docsHref}
                          target="_blank"
                          rel="noopener noreferrer"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...analyticsAttrs('header-docs')}
                        >
                          <BookOpen className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.docs}</span>
                        </a>
                      ) : (
                        <Link
                          to="/docs"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...analyticsAttrs('header-docs')}
                        >
                          <BookOpen className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.docs}</span>
                        </Link>
                      )}
                    </DropdownMenuItem>

                    <DropdownMenuItem asChild>
                      {marketingNavLinksExternal ? (
                        <a
                          href={changelogHref}
                          target="_blank"
                          rel="noopener noreferrer"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...(showChangelogBadge
                            ? { 'aria-label': headerCopy.marketingNav.changelogNewUpdatesAria }
                            : {})}
                        >
                          <Clock className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.changelog}</span>
                        </a>
                      ) : (
                        <Link
                          to="/changelog"
                          className={ACCOUNT_MENU_ITEM_CLASS}
                          {...(showChangelogBadge
                            ? { 'aria-label': headerCopy.marketingNav.changelogNewUpdatesAria }
                            : {})}
                        >
                          <Clock className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.changelog}</span>
                        </Link>
                      )}
                    </DropdownMenuItem>

                    {/* Temporary: remove once the old console is retired */}
                    <DropdownMenuItem asChild>
                      <a
                        href="https://cloud.appwrite.io"
                        target="_blank"
                        rel="noopener noreferrer"
                        className={ACCOUNT_MENU_ITEM_CLASS}
                        {...analyticsAttrs('header-old-console')}
                      >
                        <ExternalLink className="h-4 w-4" />
                        <span>{headerCopy.accountMenu.oldConsole}</span>
                      </a>
                    </DropdownMenuItem>
                  </>

                  {showAdminSection && (
                    <>
                      <DropdownMenuSeparator className="my-1 bg-border" />

                      <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                        {headerCopy.accountMenu.admin}
                      </DropdownMenuLabel>

                      <DropdownMenuItem asChild>
                        <Link to="/cache" className={ACCOUNT_MENU_ITEM_CLASS}>
                          <DatabaseZap className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.cache}</span>
                        </Link>
                      </DropdownMenuItem>

                      <DropdownMenuItem asChild>
                        <Link to="/blocks" className={ACCOUNT_MENU_ITEM_CLASS}>
                          <ShieldAlert className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.blocks}</span>
                        </Link>
                      </DropdownMenuItem>

                      <DropdownMenuItem asChild>
                        <Link to="/generator" className={ACCOUNT_MENU_ITEM_CLASS}>
                          <Sparkles className="h-4 w-4" />
                          <span>{headerCopy.accountMenu.generator}</span>
                        </Link>
                      </DropdownMenuItem>
                    </>
                  )}

                  <DropdownMenuSeparator className="my-1 bg-border" />

                  <ThemeToggle />

                  <DropdownMenuSeparator className="my-1 bg-border" />

                  <DropdownMenuItem
                    onClick={() => signOut()}
                    className={ACCOUNT_MENU_ITEM_CLASS}
                  >
                    <LogOut className="h-4 w-4" />
                    <span>{headerCopy.actions.signOut}</span>
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          )}
        </div>
      </header>
    </div>
  )
}

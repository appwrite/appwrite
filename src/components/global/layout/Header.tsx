import { useEffect, useState } from 'react'
import { ConnectProject } from '@/components/pages/projects/$projectId/shared/ConnectProject'
import { cn } from '@/lib/utils'
import { formatDateMonthYear } from '@/lib/date-utils'
import { Link } from '@tanstack/react-router'
import {
  Search,
  MessageSquare,
  ChevronDown,
  LogOut,
  User,
  CreditCard,
  Menu,
  Copy,
  Check,
  Lightbulb,
  Shield,
  Plus,
  Database,
  Users,
  Folder,
  Zap,
  Globe,
  Building2,
  FolderPlus,
  Plug2,
  ArrowUpCircle,
  ArrowLeft,
} from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ProjectSelector } from '@/components/pages/projects/$projectId/shared/ProjectSelector'
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
import { useKeyboardShortcutsContext } from '@/components/global/providers/KeyboardShortcuts'
import { ThemeToggle } from '@/components/global/shared/ThemeToggle'
import { SupportPopover } from '@/components/global/shared/SupportPopover'
import { FeedbackPopover } from '@/components/global/shared/FeedbackPopover'
import { useAIChat } from '@/components/global/providers/AIChat'
import { Button } from '@/components/ui/button'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { ImpersonateConsoleUserPopover } from '@/components/global/shared/ImpersonateConsoleUserPopover'
import { openCreateOrganizationFlow } from '@/lib/open-create-organization-flow'
import { useTheme } from 'next-themes'
import { getConsoleHeaderLogoClass } from '@/lib/html-theme'
import { ConsoleHeaderLogo } from '@/components/global/shared/ConsoleHeaderLogo'

interface ConsoleHeaderProps {
  onMenuClick?: () => void
  className?: string
  projectId?: string
  onCommandCenterOpen?: () => void
  onCreateOrganization?: () => void
  /** When true, search is hidden (e.g. when native app bar is shown above) */
  hideSearch?: boolean
}

export function ConsoleHeader({
  onMenuClick,
  className,
  projectId,
  onCommandCenterOpen,
  onCreateOrganization,
  hideSearch = false,
}: ConsoleHeaderProps) {
  const { openCommandCenter: contextOpenCommandCenter } =
    useKeyboardShortcutsContext()
  const { toggleChat } = useAIChat()
  const { account, signOut } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const params = useParams({ strict: false })
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [connectDialogOpen, setConnectDialogOpen] = useState(false)
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
  const { features } = useConsoleProfile()
  const overrides = useDebugOverrides()
  const { access } = useOrganizationScopes(project?.teamId)
  const showAIAssistant = overrides.showAIAssistant
  const showConnectAndCreate = canShowConnectSection(access, features)
  const canCreateProjectFlag = canCreateProject(access, features)
  const canCreateDatabaseFlag = canCreateDatabase(access, features)
  const canCreateUserFlag = canCreateUser(access, features)
  const canCreateBucketFlag = canCreateBucket(access, features)
  const canCreateFunctionFlag = canCreateFunction(access, features)
  const canCreateSiteFlag = canCreateSite(access, features)
  const canCreateTopicFlag = canWriteTopics(access, features)

  // Organization ID for upgrade button. Project scope: use current project's teamId only.
  // Org scope (e.g. billing): use orgId from URL so the link matches the org we're viewing.
  const orgIdFromRoute = params?.orgId as string | undefined
  const orgId = projectId
    ? (project?.teamId ?? undefined)
    : (orgIdFromRoute ?? (account?.prefs?.organization as string | undefined))

  // Fetch organization plan to check if upgrade button should be shown
  const { plan: organizationPlan } = useOrganizationPlan(orgId)
  const selfService = organizationPlan?.selfService !== false
  // Only show upgrade when current plan cost is 0 (free); hide when already on a paid plan
  const showUpgradeButton =
    features.billing &&
    orgId &&
    selfService &&
    (organizationPlan?.price ?? 0) === 0

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  // Get user display name (prefer name, fallback to email)
  const displayName = account?.name || account?.email || 'User'
  const userEmail = account?.email || ''
  const accountId = account?.$id || ''

  // Format member since date using consistent formatting
  // Account registration can be in various formats (Unix timestamp, ISO string, etc.)
  const memberSince = formatDateMonthYear(
    account?.registration || account?.createdAt || account?.$createdAt,
  )

  // Account status (active if account exists)
  const accountStatus = account ? 'Active' : 'Inactive'

  // Check if 2FA is enabled
  // Appwrite account object may have mfa or twoFactorAuthenticatorEnabled property
  const is2FAEnabled =
    account?.mfa === true || account?.twoFactorAuthenticatorEnabled === true

  const hasSidebar = !isOrgOverview
  const isAccountScope = location.pathname.startsWith('/account')
  const logoColumnWidth = 60

  return (
    <div className="@container w-full">
      <header
        className={cn(
          'flex h-14 min-h-14 flex-wrap items-center justify-between gap-1 @[640px]:gap-2 border-b border-border bg-background',
          'pl-3 pr-3 @[640px]:pl-4 @[640px]:pr-4 @[1024px]:pl-0 @[1000px]:pr-6',
          className,
        )}
      >
        {/* Left: Menu + Logo (+ nav border when project) + Project Selector */}
        <div className="flex min-w-0 flex-1 items-center gap-1.5 @[640px]:gap-2">
          {/* Mobile menu button - only show when in project context and sidebar is hidden */}
          {!isOrgOverview && (
            <button
              onClick={onMenuClick}
              className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1024px]:hidden"
            >
              <Menu className="h-5 w-5" />
            </button>
          )}

          {/* Logo - 60px column matches collapsed nav; never shifts; optional spacer + border continues from nav */}
          {(() => {
            const linkOrgId =
              project?.teamId ||
              (account?.prefs?.organization as string | undefined)
            const logoLink = (childClassName?: string) => (
              <Link
                to={linkOrgId ? '/organizations/$orgId' : '/'}
                params={linkOrgId ? { orgId: linkOrgId } : undefined}
                aria-label="Appwrite"
                className={cn(
                  'group inline-flex size-10 shrink-0 items-center justify-center rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                  childClassName,
                )}
              >
                <ConsoleHeaderLogo
                  className={cn(
                    'transition-transform duration-150 ease-out group-hover:scale-[1.04]',
                    headerLogoClassName,
                  )}
                />
              </Link>
            )

            if (hasSidebar) {
              return (
                <>
                  {/* Desktop: 60px logo column, border continues from nav */}
                  <div
                    className="hidden h-14 shrink-0 items-center justify-center border-r border-border @[1024px]:flex"
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
                Back to organization
              </Link>
            </Button>
          )}

          {/* Project Selector - only show when in project context */}
          {!isOrgOverview && (
            <>
              {/* Project Selector */}
              <div className="hidden min-w-0 @[700px]:block">
                <ProjectSelector
                  projectId={projectId}
                  className="max-w-full min-w-0"
                  onCreateOrganization={onCreateOrganization}
                />
              </div>

              {/* Connect button - only owners/developers; show when project has never received a ping */}
              {showConnectAndCreate &&
                project &&
                (project.pingCount === 0 || !project.pingedAt) &&
                projectId && (
                  <>
                    <button
                      type="button"
                      className="flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer hidden @[700px]:flex text-[13px]"
                      onClick={() => setConnectDialogOpen(true)}
                    >
                      <Plug2 className="h-4 w-4" />
                      Connect
                    </button>
                    <ConnectProject
                      open={connectDialogOpen}
                      onOpenChange={setConnectDialogOpen}
                      projectId={projectId}
                    />
                  </>
                )}

              {/* Create Button - only owners and developers */}
              {showConnectAndCreate && (
                <div className="hidden @[700px]:block shrink-0">
                  <DropdownMenu>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <DropdownMenuTrigger asChild>
                          <button className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer">
                            <Plus className="h-4 w-4" />
                          </button>
                        </DropdownMenuTrigger>
                      </TooltipTrigger>
                      <TooltipContent>
                        <p>Create</p>
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
                                <span>New Project</span>
                              </DropdownMenuItem>
                            </span>
                          </TooltipTrigger>
                          <TooltipContent>
                            <p>
                              You don&apos;t have permission to create projects.
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <DropdownMenuItem
                          onClick={() => {
                            const orgId =
                              project?.teamId ||
                              (account?.prefs?.organization as
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
                          <span>New Project</span>
                        </DropdownMenuItem>
                      )}
                      <DropdownMenuItem
                        onClick={() => {
                          const orgId =
                            project?.teamId ||
                            (account?.prefs?.organization as string | undefined)
                          openCreateOrganizationFlow(navigate, {
                            onCreateOrganization,
                            orgId,
                          })
                        }}
                        className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                      >
                        <Building2 className="h-4 w-4" />
                        <span>New Organization</span>
                      </DropdownMenuItem>

                      {projectId && (
                        <>
                          <DropdownMenuSeparator />
                          {/* Resources Category */}
                          <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                            Build
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
                                    <span>New Database</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  databases.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
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
                              <span>New Database</span>
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
                                    <span>New User</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  users.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
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
                              <span>New User</span>
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
                                    <span>New Bucket</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  buckets.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/storage',
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
                              <span>New Bucket</span>
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
                                    <span>New Function</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  functions.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/functions/create',
                                  params: { projectId },
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Zap className="h-4 w-4" />
                              <span>New Function</span>
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
                                    <span>New Message</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  messaging topics.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
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
                              <span>New Message</span>
                            </DropdownMenuItem>
                          )}

                          <DropdownMenuSeparator />
                          {/* Deploy Category */}
                          <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                            Deploy
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
                                    <span>New Site</span>
                                  </DropdownMenuItem>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p>
                                  You don&apos;t have permission to create
                                  sites.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          ) : (
                            <DropdownMenuItem
                              onClick={() => {
                                navigate({
                                  to: '/projects/$projectId/sites/create',
                                  params: { projectId },
                                })
                              }}
                              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                            >
                              <Globe className="h-4 w-4" />
                              <span>New Site</span>
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

        {/* Right: Actions */}
        <div className="flex shrink-0 items-center gap-1 @[640px]:gap-2 min-w-0">
          {/* Search - hidden on small containers or when hideSearch (e.g. native app bar) */}
          {!hideSearch && (
            <>
              <button
                onClick={openCommandCenter}
                className="hidden h-9 cursor-pointer items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent @[700px]:flex shrink-0"
              >
                <Search className="h-3.5 w-3.5 shrink-0" />
                <span className="hidden @[850px]:inline">Search...</span>
                <kbd className="ml-2 hidden rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground @[850px]:inline shrink-0">
                  ⌘K
                </kbd>
              </button>

              {/* Mobile search icon */}
              <button
                onClick={openCommandCenter}
                className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[700px]:hidden"
              >
                <Search className="h-4 w-4" />
              </button>
            </>
          )}

          {/* Feedback - hidden on small containers */}
          <div className="hidden @[800px]:flex shrink-0">
            <FeedbackPopover
              source="navbar"
              orgId={orgId}
              projectId={projectId ?? ''}
              billingPlanId={organizationPlan?.$id}
            />
          </div>

          {/* Support - hidden on small containers */}
          <div className="hidden @[900px]:flex shrink-0">
            <SupportPopover orgId={orgId} />
          </div>

          {/* Console impersonation (operators) — same control style as Support / Assistant */}
          <div className="hidden @[900px]:flex shrink-0">
            <ImpersonateConsoleUserPopover />
          </div>

          {/* Help/Assistant - hidden on small containers; enabled by profile or experimental override */}
          {showAIAssistant && (
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  onClick={toggleChat}
                  className="hidden h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1000px]:flex"
                >
                  <Lightbulb className="h-4 w-4" />
                </button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Assistant</p>
              </TooltipContent>
            </Tooltip>
          )}

          {/* Divider before Upgrade Button - hidden on small containers */}
          {showUpgradeButton && (
            <div className="mx-1 hidden h-5 w-px shrink-0 bg-border @[640px]:mx-2 @[850px]:block" />
          )}

          {/* Upgrade Button - hidden on small containers; only when plan cost is 0 */}
          {showUpgradeButton && (
            <div className="hidden @[850px]:flex shrink-0 rounded-md focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background">
              <div className="upgrade-button-wrapper">
                <Button
                  asChild
                  size="sm"
                  variant="brandCta"
                  className="h-9 shrink-0 cursor-pointer gap-1.5 px-3 text-[12px] font-semibold relative z-10 rounded-[calc(0.375rem-1px)]"
                >
                  <Link
                    to="/organizations/$orgId/change-plan"
                    params={{ orgId }}
                  >
                    <ArrowUpCircle className="h-4 w-4" />
                    Upgrade
                  </Link>
                </Button>
              </div>
            </div>
          )}

          {/* Divider - hidden on small containers */}
          <div className="mx-1 hidden h-5 w-px shrink-0 bg-border @[640px]:mx-2 @[700px]:block" />

          {/* User Menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex shrink-0 cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-accent min-w-0">
                <InitialsAvatar
                  name={displayName}
                  size="sm"
                  className="shrink-0"
                />
                <div className="hidden text-left @[800px]:block min-w-0">
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {displayName}
                  </p>
                </div>
                <ChevronDown className="hidden h-3.5 w-3.5 shrink-0 text-muted-foreground @[800px]:block" />
              </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
              align="end"
              className="w-64 border-border bg-popover p-1"
            >
              <div className="px-3 py-3">
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

              {/* Account Details */}
              <div className="px-3 py-2 space-y-4">
                {/* Member Since */}
                {account?.registration && (
                  <div>
                    <p className="text-[11px] text-muted-foreground mb-1.5">
                      Member since
                    </p>
                    <p className="text-[14px] text-foreground">{memberSince}</p>
                  </div>
                )}

                {/* Account Status */}
                <div>
                  <p className="text-[11px] text-muted-foreground mb-1.5">
                    Account status
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
                      2FA
                    </p>
                    <div className="flex items-center gap-2">
                      {is2FAEnabled ? (
                        <>
                          <Shield className="h-3.5 w-3.5 text-emerald-500" />
                          <p className="text-[14px] text-foreground">Enabled</p>
                        </>
                      ) : (
                        <>
                          <Shield className="h-3.5 w-3.5 text-muted-foreground" />
                          <p className="text-[14px] text-muted-foreground">
                            Disabled
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
                      Account ID
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
                              ? 'Copied!'
                              : 'Copy account ID'}
                          </p>
                        </TooltipContent>
                      </Tooltip>
                    </TooltipProvider>
                  </div>
                )}
              </div>

              <DropdownMenuSeparator className="my-1 bg-border" />

              <DropdownMenuItem asChild>
                <Link
                  to="/account"
                  className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                >
                  <User className="h-4 w-4" />
                  <span>Account</span>
                </Link>
              </DropdownMenuItem>

              {features.billing && (
                <DropdownMenuItem asChild>
                  <Link
                    to="/account/payments"
                    className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                  >
                    <CreditCard className="h-4 w-4" />
                    <span>Payments</span>
                  </Link>
                </DropdownMenuItem>
              )}

              <DropdownMenuSeparator className="my-1 bg-border" />

              <ThemeToggle />

              <DropdownMenuSeparator className="my-1 bg-border" />

              <DropdownMenuItem
                onClick={() => signOut()}
                className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
              >
                <LogOut className="h-4 w-4" />
                <span>Sign out</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>
    </div>
  )
}

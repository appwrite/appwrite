import { useState } from 'react'
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
} from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ProjectSelector } from '@/components/pages/projects/$projectId/shared/ProjectSelector'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { useNavigate } from '@tanstack/react-router'
import { useProject } from '@/lib/react-query/hooks'
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

interface ConsoleHeaderProps {
  onMenuClick?: () => void
  className?: string
  projectId?: string
  onCommandCenterOpen?: () => void
  onCreateOrganization?: () => void
}

export function ConsoleHeader({
  onMenuClick,
  className,
  projectId,
  onCommandCenterOpen,
  onCreateOrganization,
}: ConsoleHeaderProps) {
  const { openCommandCenter: contextOpenCommandCenter } =
    useKeyboardShortcutsContext()
  const { toggleChat } = useAIChat()
  const { account, signOut } = useAuth()
  const navigate = useNavigate()
  const [copiedField, setCopiedField] = useState<string | null>(null)

  // Use prop if provided, otherwise fall back to context
  const openCommandCenter = onCommandCenterOpen || contextOpenCommandCenter

  // Check if we're on the org overview (no projectId)
  const isOrgOverview = !projectId

  // Fetch current project to get teamId when in project context
  const { project } = useProject(projectId)

  // Get organization ID for upgrade button
  const orgId =
    project?.teamId || (account?.prefs?.organization as string | undefined)

  // Fetch organization plan to check if upgrade button should be shown
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

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

  return (
    <div className="@container w-full">
      <header
        className={cn(
          'flex h-14 min-h-14 flex-wrap items-center justify-between gap-1 sm:gap-2 border-b border-border bg-background px-3 sm:px-4 @[1000px]:px-6',
          className,
        )}
      >
        {/* Left: Menu + Logo + Project Selector */}
        <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
          {/* Mobile menu button - only show when in project context and sidebar is hidden */}
          {!isOrgOverview && (
            <button
              onClick={onMenuClick}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground lg:hidden"
            >
              <Menu className="h-5 w-5" />
            </button>
          )}

          {/* Logo - links back to org overview */}
          {(() => {
            // Use project's teamId when in project context, otherwise fall back to account prefs
            const orgId =
              project?.teamId ||
              (account?.prefs?.organization as string | undefined)
            if (orgId) {
              return (
                <Link
                  to="/organizations/$orgId"
                  params={{ orgId }}
                  className="-ml-[1px] -mr-[1px] flex shrink-0 items-center"
                >
                  <img src="/logo.svg" alt="Appwrite" className="h-5 w-5" />
                </Link>
              )
            }
            return (
              <Link
                to="/"
                className="-ml-[1px] -mr-[1px] flex shrink-0 items-center"
              >
                <img src="/logo.svg" alt="Appwrite" className="h-5 w-5" />
              </Link>
            )
          })()}

          {/* Divider and Project Selector - only show when in project context */}
          {!isOrgOverview && (
            <>
              {/* Divider */}
              <div className="mx-1 hidden h-5 w-px shrink-0 bg-border @[700px]:block" />

              {/* Project Selector */}
              <div className="hidden min-w-0 @[700px]:block">
                <ProjectSelector projectId={projectId} />
              </div>

              {/* Connect button - show when project has never received a ping */}
              {project &&
                (project.pingCount === 0 || !project.pingedAt) &&
                projectId && (
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <button
                          type="button"
                          className="flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer hidden @[700px]:flex text-[13px]"
                          onClick={() =>
                            navigate({
                              to: '/projects/$projectId/apps',
                              params: { projectId },
                            })
                          }
                        >
                          <Plug2 className="h-4 w-4" />
                          Connect
                        </button>
                      </TooltipTrigger>
                      <TooltipContent side="bottom">
                        <p>Connect your app to this project</p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                )}

              {/* Create Button */}
              <div className="hidden @[700px]:block shrink-0">
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <button className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground transition-colors hover:bg-accent hover:text-foreground cursor-pointer">
                      <Plus className="h-4 w-4" />
                    </button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" className="w-56">
                    <DropdownMenuItem
                      onClick={() => {
                        const orgId =
                          project?.teamId ||
                          (account?.prefs?.organization as string | undefined)
                        if (orgId) {
                          navigate({
                            to: '/organizations/$orgId',
                            params: { orgId },
                            search: { create: 'project' } as Record<string, unknown>,
                          })
                        }
                      }}
                      className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                    >
                      <FolderPlus className="h-4 w-4" />
                      <span>New Project</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => {
                        if (onCreateOrganization) {
                          // If callback is provided (we're on org page), use it
                          onCreateOrganization()
                        } else {
                          // Otherwise, navigate to org overview with createOrg param
                          const orgId =
                            project?.teamId ||
                            (account?.prefs?.organization as string | undefined)
                          if (orgId) {
                            navigate({
                              to: '/organizations/$orgId',
                              params: { orgId },
                              search: { createOrg: true },
                            })
                          } else {
                            // No org yet, navigate to root which will handle it
                            navigate({
                              to: '/',
                              search: { createOrg: true },
                            })
                          }
                        }
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
                          Resources
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                          onClick={() => {
                            navigate({
                              to: '/projects/$projectId/databases',
                              params: { projectId },
                              search: { create: 'database' } as Record<string, unknown>,
                            })
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <Database className="h-4 w-4" />
                          <span>New Database</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          onClick={() => {
                            navigate({
                              to: '/projects/$projectId/auth',
                              params: { projectId },
                              search: { create: 'user' } as Record<string, unknown>,
                            })
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <Users className="h-4 w-4" />
                          <span>New User</span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          onClick={() => {
                            navigate({
                              to: '/projects/$projectId/storage',
                              params: { projectId },
                              search: { create: 'bucket' } as Record<string, unknown>,
                            })
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <Folder className="h-4 w-4" />
                          <span>New Bucket</span>
                        </DropdownMenuItem>
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
                        <DropdownMenuItem
                          onClick={() => {
                            navigate({
                              to: '/projects/$projectId/messaging',
                              params: { projectId },
                              search: { create: 'topic' } as Record<string, unknown>,
                            })
                          }}
                          className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                        >
                          <MessageSquare className="h-4 w-4" />
                          <span>New Message</span>
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />
                        {/* Deploy Category */}
                        <DropdownMenuLabel className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                          Deploy
                        </DropdownMenuLabel>
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
                      </>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </>
          )}
        </div>

        {/* Right: Actions */}
        <div className="flex shrink-0 items-center gap-1 sm:gap-2 min-w-0">
          {/* Search - hidden on small containers */}
          <button
            onClick={openCommandCenter}
            className="hidden h-8 items-center gap-2 rounded-md border border-border bg-accent/50 px-3 text-[13px] text-muted-foreground transition-colors hover:border-border hover:bg-accent @[700px]:flex shrink-0"
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
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[700px]:hidden"
          >
            <Search className="h-4 w-4" />
          </button>

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
            <SupportPopover
              orgId={
                project?.teamId ||
                (account?.prefs?.organization as string | undefined)
              }
            />
          </div>

          {/* Help/Assistant - hidden on small containers */}
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                onClick={toggleChat}
                className="hidden h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[1000px]:flex"
              >
                <MessageSquare className="h-4 w-4" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              <p>Assistant</p>
            </TooltipContent>
          </Tooltip>

          {/* Divider before Upgrade Button - hidden on small containers */}
          {orgId && (
            <div className="mx-1 sm:mx-2 hidden h-5 w-px shrink-0 bg-border @[850px]:block" />
          )}

          {/* Upgrade Button - hidden on small containers */}
          {orgId && (
            <div className="hidden @[850px]:flex shrink-0">
              <div className="upgrade-button-wrapper">
                <Button
                  asChild
                  size="sm"
                  className="h-8 shrink-0 bg-[#19191C] text-[13px] text-white hover:bg-[#19191C]/90 dark:bg-[#FAFAFB] dark:text-gray-900 dark:hover:bg-[#FAFAFB]/90 relative z-10"
                >
                  <Link
                    to="/organizations/$orgId/change-plan"
                    params={{ orgId }}
                  >
                    Upgrade
                  </Link>
                </Button>
              </div>
            </div>
          )}

          {/* Divider - hidden on small containers */}
          <div className="mx-1 sm:mx-2 hidden h-5 w-px shrink-0 bg-border @[700px]:block" />

          {/* User Menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex shrink-0 items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-accent min-w-0">
                <InitialsAvatar name={displayName} size="sm" className="shrink-0" />
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

                {/* 2FA Status */}
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
                            className="flex items-center gap-1.5 group"
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

              <DropdownMenuItem asChild>
                <Link
                  to="/account/payments"
                  className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground focus:bg-accent focus:text-foreground"
                >
                  <CreditCard className="h-4 w-4" />
                  <span>Payments</span>
                </Link>
              </DropdownMenuItem>

              <DropdownMenuSeparator className="my-1 bg-border" />

              <ThemeToggle />

              <DropdownMenuSeparator className="my-1 bg-border" />

              <DropdownMenuItem
                onClick={() => signOut()}
                className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] text-red-500 hover:bg-red-500/10 hover:text-red-500 focus:bg-red-500/10 focus:text-red-500"
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

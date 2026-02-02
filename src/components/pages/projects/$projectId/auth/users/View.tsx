import { useState, useMemo, useEffect } from 'react'
import {
  useParams,
  useLocation,
  useNavigate,
  Link,
} from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import {
  ArrowLeft,
  CheckCircle2,
  XCircle,
  X,
  Trash2,
  MoreVertical,
  Mail,
  Phone,
  Smartphone,
  Shield,
  Users,
  Key,
  Activity,
  Loader2,
  Plus,
  LogOut,
  AlertCircle,
  Info,
  Ghost,
  Globe,
  Tablet,
  Monitor,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { AuthenticatorType, MessagingProviderType } from '@appwrite.io/console'
import { toast } from 'sonner'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  useUser,
  useUserMemberships,
  useUserIdentities,
  useUserTargets,
  useUserSessions,
  useUserMFAFactors,
  useUpdateUserName,
  useUpdateUserEmail,
  useUpdateUserPhone,
  useUpdateUserPassword,
  useUpdateUserLabels,
  useUpdateUserPrefs,
  useUpdateUserStatus,
  useUpdateUserEmailVerification,
  useUpdateUserPhoneVerification,
  useUpdateUserMFA,
  useDeleteUserMFAAuthenticator,
  useDeleteUserMembership,
  useDeleteUserIdentity,
  useCreateUserTarget,
  useDeleteUserTarget,
  useDeleteUserSession,
  useDeleteAllUserSessions,
  useDeleteProjectUser,
} from '@/lib/react-query/hooks/users'
import { useProject } from '@/lib/react-query/hooks'
import { ID, Browser } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

const DEFAULT_PAGE_SIZE = 25

// Browser Icon Component with Device Badge
function BrowserIcon({
  clientCode,
  deviceName,
}: {
  clientCode?: string
  deviceName?: string
}) {
  const [iconUrl, setIconUrl] = useState<string | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    if (!clientCode) {
      setError(true)
      return
    }

    const loadIcon = async () => {
      try {
        // Use higher resolution to avoid pixelation
        const url = sdk.forConsole.avatars.getBrowser({
          code: clientCode as Browser,
          width: 64,
          height: 64,
        })
        setIconUrl(url)
      } catch (err) {
        setError(true)
      }
    }

    loadIcon()
  }, [clientCode])

  // Get device icon based on deviceName
  const getDeviceIcon = () => {
    const device = deviceName?.toLowerCase()
    switch (device) {
      case 'smartphone':
        return Smartphone
      case 'tablet':
        return Tablet
      case 'desktop':
      default:
        return Monitor
    }
  }

  const DeviceIcon = getDeviceIcon()

  if (error || !iconUrl) {
    return (
      <div className="relative">
        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
          <Activity className="h-4 w-4 text-muted-foreground" />
        </div>
        {deviceName && (
          <div className="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-background ring-2 ring-background">
            <DeviceIcon className="h-2.5 w-2.5 text-muted-foreground" />
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="relative">
      <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50 overflow-hidden">
        <img
          src={iconUrl}
          alt={clientCode}
          className="h-9 w-9 object-contain p-1"
          onError={() => setError(true)}
        />
      </div>
      {deviceName && (
        <div className="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-background ring-2 ring-background">
          <DeviceIcon className="h-2.5 w-2.5 text-muted-foreground" />
        </div>
      )}
    </div>
  )
}

export function View() {
  const { projectId, userId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()

  // Fetch user data - hooks must be called unconditionally
  const {
    data: user,
    isLoading: userLoading,
    error: userError,
    isFetching,
    isError,
  } = useUser(projectId ?? '', userId ?? '')
  const { data: mfaFactors } = useUserMFAFactors(projectId ?? '', userId ?? '')
  
  // Fetch sessions for the CTA button in ServiceHeader
  const { data: sessionsData, isLoading: sessionsLoading } = useUserSessions(
    projectId,
    userId,
  )
  const sessions = sessionsData?.sessions || []
  
  // State for delete all sessions dialog - must be called before any early returns
  const [deleteAllSessionsDialogOpen, setDeleteAllSessionsDialogOpen] = useState(false)
  const deleteAllSessions = useDeleteAllUserSessions(projectId ?? '', userId ?? '')

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const userIdIndex = pathParts.findIndex((part) => part === userId)

    if (userIdIndex >= 0 && pathParts[userIdIndex + 1]) {
      const tabFromPath = pathParts[userIdIndex + 1]
      if (
        ['memberships', 'identities', 'targets', 'sessions'].includes(
          tabFromPath,
        )
      ) {
        return tabFromPath
      }
    }

    return 'overview'
  }, [location.pathname, userId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: 'Overview',
        to: '/projects/$projectId/auth/users/$userId',
        params: {
          projectId: projectId as string,
          userId: userId as string,
        },
      },
      {
        id: 'memberships',
        label: 'Memberships',
        to: '/projects/$projectId/auth/users/$userId/memberships',
        params: {
          projectId: projectId as string,
          userId: userId as string,
        },
      },
      {
        id: 'identities',
        label: 'Identities',
        to: '/projects/$projectId/auth/users/$userId/identities',
        params: {
          projectId: projectId as string,
          userId: userId as string,
        },
      },
      {
        id: 'targets',
        label: 'Targets',
        to: '/projects/$projectId/auth/users/$userId/targets',
        params: {
          projectId: projectId as string,
          userId: userId as string,
        },
      },
      {
        id: 'sessions',
        label: 'Sessions',
        to: '/projects/$projectId/auth/users/$userId/sessions',
        params: {
          projectId: projectId as string,
          userId: userId as string,
        },
      },
    ],
    [projectId, userId],
  )

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/auth',
      params: { projectId: projectId! },
    })
  }

  // Early return if missing required params - after all hooks are called
  if (!projectId || !userId) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            Missing project ID or user ID
          </p>
          {!projectId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              Project ID is required
            </p>
          )}
          {!userId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              User ID is required
            </p>
          )}
        </div>
      </div>
    )
  }

  if (userLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (userError) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-destructive mb-2">
            Error loading user
          </p>
          <p className="text-[12px] text-muted-foreground">
            {userError instanceof Error ? userError.message : 'Unknown error'}
          </p>
        </div>
      </div>
    )
  }

  if (!user) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">User not found</p>
          {projectId && userId && (
            <p className="text-[12px] text-muted-foreground mt-2">
              Project: {projectId}, User: {userId}
            </p>
          )}
        </div>
      </div>
    )
  }

  const userName = user.name || '-'
  const displayName = user.name || user.email || user.phone || 'Anonymous'
  
  const handleDeleteAllSessions = () => {
    deleteAllSessions.mutate(undefined, {
      onSuccess: () => {
        toast.success('All sessions have been deleted')
        setDeleteAllSessionsDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to delete all sessions')
      },
    })
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{userName}</span>
            <CopyableId id={user.$id} size="xs" />
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        showFilters={false}
        fullWidthBorder
        beforeCreateButtons={
          activeTab === 'sessions' && !sessionsLoading && sessions.length > 0 ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteAllSessionsDialogOpen(true)}
              disabled={deleteAllSessions.isPending}
            >
              <LogOut className="mr-1.5 h-3.5 w-3.5" />
              Delete all sessions
            </Button>
          ) : undefined
        }
        contentAfterBorder={
          activeTab === 'targets' ? (
            <div className="border-b border-border bg-blue-500/5">
              <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-blue-500/30 bg-transparent"
                >
                  <Info className="h-4 w-4 text-blue-500" />
                  <AlertTitle className="text-[13px] font-medium text-blue-600 dark:text-blue-400">
                    User targets
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-blue-600/80 dark:text-blue-400/80">
                    User targets include emails, phone numbers, and devices with
                    your app installed. These targets can subscribe to a topic
                    and receive messages published to it.
                  </AlertDescription>
                </Alert>
              </div>
            </div>
          ) : undefined
        }
      />

      <div className="flex-1 flex flex-col">
        <div className={cn('mx-auto w-full max-w-7xl flex-1')}>
          {activeTab === 'overview' && (
            <div className="px-4 py-4 sm:px-6">
              <OverviewTab
                user={user}
                mfaFactors={mfaFactors}
                projectId={projectId!}
                userId={userId!}
                displayName={displayName}
              />
            </div>
          )}
          {activeTab === 'memberships' && (
            <div className="px-4 py-4 sm:px-6">
              <MembershipsTab projectId={projectId!} userId={userId!} />
            </div>
          )}
          {activeTab === 'identities' && (
            <div className="px-4 py-4 sm:px-6">
              <IdentitiesTab projectId={projectId!} userId={userId!} />
            </div>
          )}
          {activeTab === 'targets' && (
            <div className="px-4 py-4 sm:px-6">
              <TargetsTab projectId={projectId!} userId={userId!} />
            </div>
          )}
          {activeTab === 'sessions' && (
            <div className="px-4 py-4 sm:px-6">
              <SessionsTab
                projectId={projectId!}
                userId={userId!}
                displayName={displayName}
              />
            </div>
          )}
        </div>
      </div>

      {/* Delete All Sessions Confirmation Dialog */}
      {activeTab === 'sessions' && (
        <Dialog
          open={deleteAllSessionsDialogOpen}
          onOpenChange={setDeleteAllSessionsDialogOpen}
        >
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete all sessions</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete{' '}
                <strong>all of {displayName}'s sessions</strong>? This action
                cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteAllSessionsDialogOpen(false)}
                disabled={deleteAllSessions.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={handleDeleteAllSessions}
                disabled={deleteAllSessions.isPending}
              >
                Delete all sessions
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </div>
  )
}

// ============================================================================
// OVERVIEW TAB
// ============================================================================

interface OverviewTabProps {
  user: Models.User
  mfaFactors: { totp: boolean; authenticators: any[] } | undefined
  projectId: string
  userId: string
  displayName: string
}

function OverviewTab({
  user,
  mfaFactors,
  projectId,
  userId,
  displayName,
}: OverviewTabProps) {
  return (
    <div className="space-y-6 overflow-x-hidden w-full min-w-0">
      <UserStatusCard
        user={user}
        projectId={projectId}
        userId={userId}
        displayName={displayName}
      />
      <UpdateNameSection user={user} projectId={projectId} userId={userId} />
      <UpdateEmailSection user={user} projectId={projectId} userId={userId} />
      <UpdatePhoneSection user={user} projectId={projectId} userId={userId} />
      <UpdatePasswordSection projectId={projectId} userId={userId} />
      <UpdateLabelsSection user={user} projectId={projectId} userId={userId} />
      <UpdatePreferencesSection
        user={user}
        projectId={projectId}
        userId={userId}
      />
      <UpdateMFASection
        user={user}
        mfaFactors={mfaFactors}
        projectId={projectId}
        userId={userId}
      />
      <DeleteUserSection
        user={user}
        projectId={projectId}
        userId={userId}
        displayName={displayName}
      />
    </div>
  )
}

// User Status Card Component
function UserStatusCard({
  user,
  projectId,
  userId,
  displayName,
}: {
  user: Models.User
  projectId: string
  userId: string
  displayName: string
}) {
  const [verifyMenuOpen, setVerifyMenuOpen] = useState(false)
  const updateEmailVerification = useUpdateUserEmailVerification(
    projectId,
    userId,
  )
  const updatePhoneVerification = useUpdateUserPhoneVerification(
    projectId,
    userId,
  )
  const updateStatus = useUpdateUserStatus(projectId, userId)

  const hasEmail = !!user.email
  const hasPhone = !!user.phone
  const emailVerified = !!user.emailVerification
  const phoneVerified = !!user.phoneVerification
  const isBlocked = user.status === false

  const getStatusBadge = () => {
    if (isBlocked) {
      return { label: 'blocked', variant: 'warning' as const }
    }
    if (emailVerified && phoneVerified) {
      return { label: 'verified', variant: 'success' as const }
    }
    if (emailVerified) {
      return { label: 'verified email', variant: 'success' as const }
    }
    if (phoneVerified) {
      return { label: 'verified phone', variant: 'success' as const }
    }
    return { label: 'unverified', variant: 'secondary' as const }
  }

  const statusBadge = getStatusBadge()

  const handleVerifyEmail = () => {
    updateEmailVerification.mutate(!emailVerified, {
      onSuccess: () => {
        toast.success(
          `${displayName}'s email has been ${emailVerified ? 'unverified' : 'verified'}`,
        )
        setVerifyMenuOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update email verification')
      },
    })
  }

  const handleVerifyPhone = () => {
    updatePhoneVerification.mutate(!phoneVerified, {
      onSuccess: () => {
        toast.success(
          `${displayName}'s phone has been ${phoneVerified ? 'unverified' : 'verified'}`,
        )
        setVerifyMenuOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update phone verification')
      },
    })
  }

  const handleBlockToggle = () => {
    updateStatus.mutate(isBlocked, {
      onSuccess: () => {
        toast.success(
          `${displayName} has been ${isBlocked ? 'unblocked' : 'blocked'}`,
        )
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update status')
      },
    })
  }

  const lastActivity = user.accessedAt ? new Date(user.accessedAt) : null
  const joinedDate = user.$createdAt ? new Date(user.$createdAt) : null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Status</h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-start gap-4 flex-wrap">
          <InitialsAvatar name={displayName} size="lg" className="shrink-0" />
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1 flex-wrap">
              <p className="text-[15px] font-medium text-foreground truncate">
                {displayName}
              </p>
              <Badge
                variant={statusBadge.variant}
                className="text-[12px] font-medium border shrink-0"
              >
                {statusBadge.label}
              </Badge>
            </div>
            <div className="space-y-1 text-[13px] text-muted-foreground">
              {user.email && (
                <div className="flex items-center gap-1.5 min-w-0">
                  <Mail className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{user.email}</span>
                </div>
              )}
              {user.phone && (
                <div className="flex items-center gap-1.5 min-w-0">
                  <Phone className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{user.phone}</span>
                </div>
              )}
              {joinedDate && (
                <div className="flex items-center gap-1.5">
                  <span>Joined:</span>
                  <DateTooltip date={joinedDate} />
                </div>
              )}
              {lastActivity ? (
                <div className="flex items-center gap-1.5">
                  <span>Last activity:</span>
                  <DateTooltip date={lastActivity} />
                </div>
              ) : (
                <div className="flex items-center gap-1.5">
                  <span>Last activity: never</span>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        {hasEmail && (
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleVerifyEmail}
            disabled={updateEmailVerification.isPending}
          >
            {emailVerified ? 'Unverify' : 'Verify'} Email
          </Button>
        )}
        {hasPhone && (
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleVerifyPhone}
            disabled={updatePhoneVerification.isPending}
          >
            {phoneVerified ? 'Unverify' : 'Verify'} Phone
          </Button>
        )}
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={handleBlockToggle}
          disabled={updateStatus.isPending}
        >
          {isBlocked ? 'Unblock' : 'Block'} Account
        </Button>
      </div>
    </div>
  )
}

// Update Name Section
function UpdateNameSection({
  user,
  projectId,
  userId,
}: {
  user: Models.User
  projectId: string
  userId: string
}) {
  const [userName, setUserName] = useState(user.name || '')
  const updateName = useUpdateUserName(projectId, userId)

  useEffect(() => {
    setUserName(user.name || '')
  }, [user.name])

  const hasChanges = userName !== (user.name || '')
  const isDisabled = !hasChanges || updateName.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updateName.mutate(userName, {
        onSuccess: () => {
          toast.success('Name has been updated')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update name')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update name
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Update the user's display name.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="name">Name</Label>
            <Input
              id="name"
              type="text"
              placeholder="Enter name"
              value={userName}
              onChange={(e) => setUserName(e.target.value)}
              disabled={updateName.isPending}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              autoComplete="off"
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update Email Section
function UpdateEmailSection({
  user,
  projectId,
  userId,
}: {
  user: Models.User
  projectId: string
  userId: string
}) {
  const [userEmail, setUserEmail] = useState(user.email || '')
  const updateEmail = useUpdateUserEmail(projectId, userId)

  useEffect(() => {
    setUserEmail(user.email || '')
  }, [user.email])

  const hasChanges = userEmail !== (user.email || '')
  const isDisabled = !hasChanges || updateEmail.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updateEmail.mutate(userEmail, {
        onSuccess: () => {
          toast.success('Email has been updated')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update email')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update email
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Update the user's email address.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              placeholder="Enter email"
              value={userEmail}
              onChange={(e) => setUserEmail(e.target.value)}
              disabled={updateEmail.isPending}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              autoComplete="off"
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update Phone Section
function UpdatePhoneSection({
  user,
  projectId,
  userId,
}: {
  user: Models.User
  projectId: string
  userId: string
}) {
  const [userPhone, setUserPhone] = useState(user.phone || '')
  const updatePhone = useUpdateUserPhone(projectId, userId)

  useEffect(() => {
    setUserPhone(user.phone || '')
  }, [user.phone])

  const hasChanges = userPhone !== (user.phone || '')
  const isDisabled = !hasChanges || updatePhone.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updatePhone.mutate(userPhone, {
        onSuccess: () => {
          toast.success('Phone has been updated')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update phone')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update phone
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Update the user's phone number.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="phone">Phone</Label>
            <Input
              id="phone"
              type="tel"
              placeholder="For example: +14155552671"
              value={userPhone}
              onChange={(e) => setUserPhone(e.target.value)}
              disabled={updatePhone.isPending}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              autoComplete="off"
            />
            <p className="text-[12px] text-muted-foreground">
              Phone number must start with '+' and maximum of 15 digits.
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update Password Section
function UpdatePasswordSection({
  projectId,
  userId,
}: {
  projectId: string
  userId: string
}) {
  const [newPassword, setNewPassword] = useState('')
  const updatePassword = useUpdateUserPassword(projectId, userId)

  const isDisabled = !newPassword || updatePassword.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updatePassword.mutate(newPassword, {
        onSuccess: () => {
          toast.success('Password has been updated')
          setNewPassword('')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update password')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update password
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Update the user's password.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-2">
            <Label htmlFor="password">New password</Label>
            <Input
              id="password"
              type="password"
              placeholder="Enter new password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              disabled={updatePassword.isPending}
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              autoComplete="off"
            />
            <p className="text-[12px] text-muted-foreground">
              A password must contain at least 8 characters.
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update Labels Section
function UpdateLabelsSection({
  user,
  projectId,
  userId,
}: {
  user: Models.User
  projectId: string
  userId: string
}) {
  const [labels, setLabels] = useState<string[]>(user.labels || [])
  const [labelInput, setLabelInput] = useState('')
  const [error, setError] = useState<string | null>(null)
  const updateLabels = useUpdateUserLabels(projectId, userId)

  useEffect(() => {
    setLabels(user.labels || [])
  }, [user.labels])

  const suggestedLabels = ['admin', 'premium', 'mvp']

  const validateLabel = (label: string): boolean => {
    return /^[a-zA-Z0-9]+$/.test(label)
  }

  const handleAddLabel = (label: string) => {
    const trimmed = label.trim()
    if (!trimmed) return

    if (!validateLabel(trimmed)) {
      setError('Only alphanumeric characters are allowed')
      return
    }

    if (labels.includes(trimmed)) {
      return
    }

    setLabels([...labels, trimmed])
    setLabelInput('')
    setError(null)
  }

  const handleRemoveLabel = (labelToRemove: string) => {
    setLabels(labels.filter((l) => l !== labelToRemove))
    setError(null)
  }

  const handleLabelInputKeyDown = (
    e: React.KeyboardEvent<HTMLInputElement>,
  ) => {
    if (e.key === 'Enter' && labelInput.trim()) {
      e.preventDefault()
      handleAddLabel(labelInput)
    } else if (e.key === ',' && labelInput.trim()) {
      e.preventDefault()
      handleAddLabel(labelInput)
    } else if (e.key === ' ' && labelInput.trim()) {
      e.preventDefault()
      handleAddLabel(labelInput)
    } else if (
      (e.key === 'Backspace' || e.key === 'Delete') &&
      !labelInput.trim() &&
      labels.length > 0
    ) {
      e.preventDefault()
      handleRemoveLabel(labels[labels.length - 1])
    }
  }

  // Check if labels have changed (symmetric difference)
  const originalLabels = new Set(user.labels || [])
  const currentLabels = new Set(labels)
  const hasChanges =
    originalLabels.size !== currentLabels.size ||
    [...originalLabels].some((label) => !currentLabels.has(label)) ||
    [...currentLabels].some((label) => !originalLabels.has(label))

  const isDisabled = !!error || !hasChanges || updateLabels.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updateLabels.mutate(labels, {
        onSuccess: () => {
          toast.success('User labels have been updated')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update labels')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update labels
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Categorize and manage your users based on specific criteria by
          assigning them customizable labels. New label-based roles will be
          assigned.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-3">
            <div className="space-y-2">
              <Label
                htmlFor="labels"
                className="text-[13px] font-medium text-foreground"
              >
                Labels
              </Label>
              <p className="text-[12px] text-muted-foreground">
                Type and press Enter or comma to add labels
              </p>
              <div className="relative max-w-md">
                <div
                  className={cn(
                    'flex flex-wrap items-center gap-1.5 min-h-[36px] rounded-md border bg-transparent px-3 py-1.5 text-sm transition-[color,box-shadow] outline-none',
                    'focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]',
                    updateLabels.isPending
                      ? 'opacity-50 cursor-not-allowed'
                      : '',
                  )}
                >
                  {labels.map((label) => (
                    <Badge
                      key={label}
                      variant="secondary"
                      className="gap-1 h-6 text-[11px] px-1.5 py-0 bg-muted border-border"
                    >
                      {label}
                      <button
                        type="button"
                        onClick={() => handleRemoveLabel(label)}
                        className="ml-0.5 rounded-full hover:bg-muted/80 p-0.5"
                        disabled={updateLabels.isPending}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </Badge>
                  ))}
                  <input
                    id="labels"
                    type="text"
                    value={labelInput}
                    onChange={(e) => {
                      setLabelInput(e.target.value)
                      setError(null)
                    }}
                    onKeyDown={handleLabelInputKeyDown}
                    placeholder={
                      labels.length === 0 ? 'Enter label (e.g., admin)' : ''
                    }
                    className="flex-1 min-w-[120px] bg-transparent border-0 outline-none text-sm placeholder:text-muted-foreground"
                    disabled={updateLabels.isPending}
                    autoComplete="off"
                  />
                </div>
              </div>
              {error && (
                <p className="text-[12px] text-destructive mt-1">{error}</p>
              )}
              <p className="text-[12px] text-muted-foreground">
                Only alphanumeric characters are allowed
              </p>
            </div>
            {suggestedLabels.length > 0 && (
              <div className="space-y-1.5">
                <p className="text-[12px] text-muted-foreground">Suggested:</p>
                <div className="flex flex-wrap gap-2">
                  {suggestedLabels.map((label) => (
                    <Button
                      key={label}
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      onClick={() => handleAddLabel(label)}
                      disabled={
                        updateLabels.isPending || labels.includes(label)
                      }
                    >
                      <Plus className="h-3 w-3 mr-1" />
                      {label}
                    </Button>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update Preferences Section
function UpdatePreferencesSection({
  user,
  projectId,
  userId,
}: {
  user: Models.User
  projectId: string
  userId: string
}) {
  const [preferences, setPreferences] = useState<
    Array<{ key: string; value: string }>
  >([])
  const updatePrefs = useUpdateUserPrefs(projectId, userId)

  useEffect(() => {
    const prefs = user.prefs || {}
    setPreferences(
      Object.entries(prefs).map(([key, value]) => ({
        key,
        value: String(value),
      })),
    )
    if (Object.keys(prefs).length === 0) {
      setPreferences([{ key: '', value: '' }])
    }
  }, [user.prefs])

  const handleAddPreference = () => {
    setPreferences([...preferences, { key: '', value: '' }])
  }

  const handleRemovePreference = (index: number) => {
    if (
      preferences.length === 1 &&
      !preferences[0].key &&
      !preferences[0].value
    ) {
      return
    }
    setPreferences(preferences.filter((_, i) => i !== index))
  }

  const handlePreferenceChange = (
    index: number,
    field: 'key' | 'value',
    value: string,
  ) => {
    const updated = [...preferences]
    updated[index] = { ...updated[index], [field]: value }
    setPreferences(updated)
  }

  const originalPrefs = user.prefs || {}
  const currentPrefs: Record<string, string> = {}
  preferences.forEach((pref) => {
    if (pref.key && pref.value) {
      currentPrefs[pref.key] = pref.value
    }
  })

  const hasChanges =
    JSON.stringify(originalPrefs) !== JSON.stringify(currentPrefs)
  const lastRowIncomplete =
    preferences.length > 0 &&
    (!preferences[preferences.length - 1].key ||
      !preferences[preferences.length - 1].value)

  const isDisabled = !hasChanges || lastRowIncomplete || updatePrefs.isPending

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!isDisabled) {
      updatePrefs.mutate(currentPrefs, {
        onSuccess: () => {
          toast.success('Preferences have been updated')
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update preferences')
        },
      })
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Update preferences
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Update user preferences as key-value pairs.
        </p>
      </div>
      <form onSubmit={handleSubmit}>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-3">
            {preferences.map((pref, index) => (
              <div key={index} className="flex items-center gap-2">
                <Input
                  type="text"
                  placeholder="Key"
                  value={pref.key}
                  onChange={(e) =>
                    handlePreferenceChange(index, 'key', e.target.value)
                  }
                  disabled={updatePrefs.isPending}
                  className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
                <Input
                  type="text"
                  placeholder="Value"
                  value={pref.value}
                  onChange={(e) =>
                    handlePreferenceChange(index, 'value', e.target.value)
                  }
                  disabled={updatePrefs.isPending}
                  className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-9 w-9 p-0"
                  onClick={() => handleRemovePreference(index)}
                  disabled={
                    preferences.length === 1 &&
                    !preferences[0].key &&
                    !preferences[0].value
                  }
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleAddPreference}
              disabled={lastRowIncomplete || updatePrefs.isPending}
            >
              <Plus className="mr-1.5 h-3.5 w-3.5" />
              Add preference
            </Button>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            type="submit"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isDisabled}
          >
            Update
          </Button>
        </div>
      </form>
    </div>
  )
}

// Update MFA Section
function UpdateMFASection({
  user,
  mfaFactors,
  projectId,
  userId,
}: {
  user: Models.User
  mfaFactors: { totp: boolean; authenticators: any[] } | undefined
  projectId: string
  userId: string
}) {
  const [userMfa, setUserMfa] = useState(!!user.mfa)
  const updateMFA = useUpdateUserMFA(projectId, userId)
  const deleteAuthenticator = useDeleteUserMFAAuthenticator(projectId, userId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [authenticatorToDelete, setAuthenticatorToDelete] = useState<any>(null)

  useEffect(() => {
    setUserMfa(!!user.mfa)
  }, [user.mfa])

  const handleMfaToggle = (checked: boolean) => {
    setUserMfa(checked)
    updateMFA.mutate(checked, {
      onSuccess: () => {
        toast.success(
          `Multi-factor authentication has been ${checked ? 'enabled' : 'disabled'}`,
        )
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update MFA')
        // Revert on error
        setUserMfa(!!user.mfa)
      },
    })
  }

  const handleDeleteAuthenticator = (authenticator: any) => {
    setAuthenticatorToDelete(authenticator)
    setDeleteDialogOpen(true)
  }

  const confirmDeleteAuthenticator = () => {
    if (authenticatorToDelete) {
      deleteAuthenticator.mutate(authenticatorToDelete.type, {
        onSuccess: () => {
          toast.success('Authentication method has been deleted')
          setDeleteDialogOpen(false)
          setAuthenticatorToDelete(null)
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to delete authenticator')
        },
      })
    }
  }

  const authenticators = mfaFactors?.authenticators || []
  const hasTOTP = authenticators.some(
    (auth) => auth.type === AuthenticatorType.Totp,
  )

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Multi-factor authentication
          </h3>
          <p className="text-[13px] text-muted-foreground mt-1">
            Enhance the user's account security by requiring a second sign-in
            method
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-6">
          <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-4">
            <div className="space-y-0.5">
              <Label
                htmlFor="mfa-toggle"
                className="text-[13px] font-semibold text-foreground cursor-pointer"
              >
                Multi-factor authentication
              </Label>
              <p className="text-[12px] text-muted-foreground">
                {userMfa
                  ? 'MFA is currently enabled'
                  : 'MFA is currently disabled'}
              </p>
            </div>
            <Switch
              id="mfa-toggle"
              checked={userMfa}
              onCheckedChange={handleMfaToggle}
              disabled={updateMFA.isPending}
            />
          </div>

          {userMfa && (
            <div className="space-y-4">
              <div className="flex items-start gap-4 rounded-lg border border-border bg-card/50 p-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                  <Smartphone className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <h4 className="text-[14px] font-semibold text-foreground">
                      Authenticator app
                    </h4>
                    {hasTOTP && (
                      <Badge
                        variant="secondary"
                        className="h-5 gap-1 px-1.5 text-[11px]"
                      >
                        <CheckCircle2 className="h-3 w-3" />
                        connected
                      </Badge>
                    )}
                  </div>
                  <p className="text-[13px] text-muted-foreground">
                    {hasTOTP
                      ? 'User has connected an authenticator app for two-factor authentication.'
                      : 'No authenticator app has been connected yet.'}
                  </p>
                </div>
                {hasTOTP && authenticators.length > 0 && (
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                      onClick={() =>
                        handleDeleteAuthenticator(
                          authenticators.find(
                            (a) => a.type === AuthenticatorType.Totp,
                          ),
                        )
                      }
                      disabled={deleteAuthenticator.isPending}
                    >
                      Delete
                    </Button>
                  </div>
                )}
              </div>

              {hasTOTP && authenticators.length > 0 && (
                <div className="rounded-lg border border-border">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Type
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]">
                          Actions
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {authenticators.map((auth) => (
                        <TableRow key={auth.$id}>
                          <TableCell className="px-4 py-3 text-[13px]">
                            {auth.type === AuthenticatorType.Totp
                              ? 'TOTP'
                              : auth.type}
                          </TableCell>
                          <TableCell className="px-4 py-3 text-right">
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0"
                              onClick={() => handleDeleteAuthenticator(auth)}
                              disabled={deleteAuthenticator.isPending}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete authentication method</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this authentication method? This
              action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={confirmDeleteAuthenticator}
              disabled={deleteAuthenticator.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// Delete User Section
function DeleteUserSection({
  user,
  projectId,
  userId,
  displayName,
}: {
  user: Models.User
  projectId: string
  userId: string
  displayName: string
}) {
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteUser = useDeleteProjectUser(projectId)

  const handleDelete = () => {
    deleteUser.mutate(userId, {
      onSuccess: () => {
        toast.success(`${displayName} has been deleted`)
        navigate({
          to: '/projects/$projectId/auth',
          params: { projectId: projectId! },
        })
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to delete user')
      },
    })
  }

  const lastActivity = user.accessedAt ? new Date(user.accessedAt) : null

  return (
    <>
      <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Delete user
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            Permanently delete this user from the project. This action cannot be
            undone.
          </p>

          {/* User Info Summary */}
          <div className="flex items-center gap-3 mt-4">
            <InitialsAvatar name={displayName} size="md" />
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {displayName}
              </p>
              <p className="text-[12px] text-muted-foreground">
                {(() => {
                  const parts = []
                  if (user.email) parts.push(user.email)
                  if (user.phone) parts.push(user.phone)
                  if (lastActivity) {
                    parts.push(
                      <>
                        Last activity: <DateTooltip date={lastActivity} />
                      </>,
                    )
                  }
                  return parts.map((item, index) => (
                    <span key={index}>
                      {item}
                      {index < parts.length - 1 && ' • '}
                    </span>
                  ))
                })()}
              </p>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
          <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
            <DialogTrigger asChild>
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
              >
                Delete user
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>Delete user</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Are you sure you want to delete{' '}
                  <strong>
                    {displayName} · {project?.name || 'this project'}
                  </strong>
                  ? This action cannot be undone.
                </DialogDescription>
              </DialogHeader>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setDeleteDialogOpen(false)}
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleDelete}
                  disabled={deleteUser.isPending}
                >
                  Delete
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </>
  )
}

// ============================================================================
// MEMBERSHIPS TAB
// ============================================================================

function MembershipsTab({
  projectId,
  userId,
}: {
  projectId: string
  userId: string
}) {
  const { data, isLoading } = useUserMemberships(projectId, userId)
  const [selectedMemberships, setSelectedMemberships] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [membershipToDelete, setMembershipToDelete] =
    useState<Models.Membership | null>(null)
  const deleteMembership = useDeleteUserMembership(projectId)
  const navigate = useNavigate()

  const memberships = data?.memberships || []
  const total = data?.total || 0

  const handleDelete = (membership: Models.Membership) => {
    setMembershipToDelete(membership)
    setDeleteDialogOpen(true)
  }

  const confirmDelete = () => {
    if (membershipToDelete) {
      deleteMembership.mutate(
        {
          teamId: membershipToDelete.teamId,
          membershipId: membershipToDelete.$id,
        },
        {
          onSuccess: () => {
            toast.success('Membership has been deleted')
            setDeleteDialogOpen(false)
            setMembershipToDelete(null)
            navigate({
              to: '/projects/$projectId/auth/users/$userId/memberships',
              params: { projectId, userId },
            })
          },
          onError: (error: Error) => {
            toast.error(error.message || 'Failed to delete membership')
          },
        },
      )
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (memberships.length === 0) {
    return (
      <div className="rounded-lg border border-border bg-card py-12">
        <EmptyState
          icon={Users}
          title="No memberships available"
          description="This user is not a member of any teams."
          isEmpty={true}
          iconSize="md"
        />
        <div className="mt-4 text-center">
          <a
            href="https://appwrite.io/docs/users"
            className="text-[13px] text-primary hover:underline"
            target="_blank"
            rel="noopener noreferrer"
          >
            Learn more about memberships
          </a>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="w-[40px] px-4">
                <Checkbox
                  checked={
                    memberships.length > 0 &&
                    selectedMemberships.size === memberships.length
                  }
                  onCheckedChange={(checked) => {
                    if (checked) {
                      setSelectedMemberships(
                        new Set(memberships.map((m) => m.$id)),
                      )
                    } else {
                      setSelectedMemberships(new Set())
                    }
                  }}
                />
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Name
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Roles
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                Joined
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                Actions
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {memberships.map((membership) => (
              <TableRow key={membership.$id}>
                <TableCell className="px-4 py-3">
                  <Checkbox
                    checked={selectedMemberships.has(membership.$id)}
                    onCheckedChange={(checked) => {
                      const newSelected = new Set(selectedMemberships)
                      if (checked) {
                        newSelected.add(membership.$id)
                      } else {
                        newSelected.delete(membership.$id)
                      }
                      setSelectedMemberships(newSelected)
                    }}
                  />
                </TableCell>
                <TableCell className="px-4 py-3">
                  <Link
                    to="/projects/$projectId/auth/teams/$teamId"
                    params={{ projectId, teamId: membership.teamId }}
                    className="flex items-center gap-2 hover:underline"
                  >
                    <InitialsAvatar name={membership.teamName} size="sm" />
                    <span className="text-[13px] font-medium text-foreground">
                      {membership.teamName}
                    </span>
                  </Link>
                </TableCell>
                <TableCell className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {membership.roles.map((role) => (
                      <Badge
                        key={role}
                        variant="secondary"
                        className="text-[11px]"
                      >
                        {role}
                      </Badge>
                    ))}
                  </div>
                </TableCell>
                <TableCell className="px-4 py-3">
                  <DateTooltip
                    date={new Date(membership.joined)}
                    className="text-[12px] text-muted-foreground"
                  />
                </TableCell>
                <TableCell className="px-4 py-3">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 p-0"
                    onClick={() => handleDelete(membership)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete member</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>
                {membershipToDelete?.userName || 'this user'} ·{' '}
                {membershipToDelete?.teamName || 'this team'}
              </strong>
              ? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={confirmDelete}
              disabled={deleteMembership.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// ============================================================================
// IDENTITIES TAB
// ============================================================================

function IdentitiesTab({
  projectId,
  userId,
}: {
  projectId: string
  userId: string
}) {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [selectedIdentities, setSelectedIdentities] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteIdentity = useDeleteUserIdentity(projectId, userId)

  const { data, isLoading } = useUserIdentities(
    projectId,
    userId,
    page - 1,
    DEFAULT_PAGE_SIZE,
    search,
  )

  const identities = data?.identities || []
  const total = data?.total || 0

  const getProviderIcon = (provider: string) => {
    const providerMap: Record<string, string> = {
      github: 'github.svg',
      google: 'google.svg',
      apple: 'apple.svg',
      facebook: 'facebook.svg',
      microsoft: 'microsoft.svg',
      linkedin: 'linkedin.svg',
      twitter: 'twitter.svg',
      amazon: 'amazon.svg',
      bitbucket: 'bitbucket.svg',
      gitlab: 'gitlab.svg',
      discord: 'discord.svg',
      spotify: 'spotify.svg',
      slack: 'slack.svg',
      salesforce: 'salesforce.svg',
      paypal: 'paypal.svg',
      okta: 'okta.svg',
      auth0: 'auth0.svg',
      authentik: 'authentik.svg',
      oidc: 'oidc.svg',
    }
    return providerMap[provider.toLowerCase()] || 'empty.svg'
  }

  const getProviderName = (provider: string) => {
    const nameMap: Record<string, string> = {
      github: 'GitHub',
      google: 'Google',
      apple: 'Apple',
      facebook: 'Facebook',
      microsoft: 'Microsoft',
      linkedin: 'LinkedIn',
      twitter: 'Twitter',
      amazon: 'Amazon',
      bitbucket: 'Bitbucket',
      gitlab: 'GitLab',
      discord: 'Discord',
      spotify: 'Spotify',
      slack: 'Slack',
      salesforce: 'Salesforce',
      paypal: 'PayPal',
      okta: 'Okta',
      auth0: 'Auth0',
      authentik: 'Authentik',
      oidc: 'OIDC',
    }
    return nameMap[provider.toLowerCase()] || provider
  }

  const handleBulkDelete = async () => {
    if (selectedIdentities.size === 0) return

    const identityIds = Array.from(selectedIdentities)
    try {
      await Promise.all(identityIds.map((id) => deleteIdentity.mutateAsync(id)))
      toast.success(
        `${identityIds.length} identit${identityIds.length === 1 ? 'y' : 'ies'} have been deleted`,
      )
      setSelectedIdentities(new Set())
      setDeleteDialogOpen(false)
    } catch (error: any) {
      toast.error(error.message || 'Failed to delete identities')
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (identities.length === 0) {
    return (
      <div className="rounded-lg border border-border bg-card py-12">
        <EmptyState
          icon={Key}
          title="No identities available"
          description="No OAuth identities linked to this user."
          isEmpty={true}
          iconSize="md"
        />
        <div className="mt-4 text-center">
          <a
            href="https://appwrite.io/docs/users"
            className="text-[13px] text-primary hover:underline"
            target="_blank"
            rel="noopener noreferrer"
          >
            Learn more about identities
          </a>
        </div>
      </div>
    )
  }

  return (
    <>
      <div className="space-y-4">
        <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-4 py-3">
          <Info className="h-4 w-4 text-muted-foreground" />
          <p className="text-[13px] text-muted-foreground">
            User identities are the user's connected OAuth accounts. The user
            can sign in using these identities.
          </p>
        </div>

        {selectedIdentities.size > 0 && (
          <div className="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3">
            <span className="text-[13px] text-foreground">
              {selectedIdentities.size} identit
              {selectedIdentities.size === 1 ? 'y' : 'ies'} selected
            </span>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteDialogOpen(true)}
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" />
              Delete selected
            </Button>
          </div>
        )}

        <div className="rounded-lg border border-border bg-card overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="w-[40px] px-4">
                  <Checkbox
                    checked={
                      identities.length > 0 &&
                      selectedIdentities.size === identities.length
                    }
                    onCheckedChange={(checked) => {
                      if (checked) {
                        setSelectedIdentities(
                          new Set(identities.map((i) => i.$id)),
                        )
                      } else {
                        setSelectedIdentities(new Set())
                      }
                    }}
                  />
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Identity ID
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Provider
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Email
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Created
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                  Actions
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {identities.map((identity) => (
                <TableRow key={identity.$id}>
                  <TableCell className="px-4 py-3">
                    <Checkbox
                      checked={selectedIdentities.has(identity.$id)}
                      onCheckedChange={(checked) => {
                        const newSelected = new Set(selectedIdentities)
                        if (checked) {
                          newSelected.add(identity.$id)
                        } else {
                          newSelected.delete(identity.$id)
                        }
                        setSelectedIdentities(newSelected)
                      }}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <CopyableId id={identity.$id} size="xs" />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <img
                        src={`/icons/${getProviderIcon(identity.provider)}`}
                        alt={identity.provider}
                        className="h-4 w-4"
                        onError={(e) => {
                          e.currentTarget.src = '/icons/empty.svg'
                        }}
                      />
                      <span className="text-[13px] font-medium text-foreground">
                        {getProviderName(identity.provider)}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px]">
                    {identity.providerEmail || '-'}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={new Date(identity.$createdAt)}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      onClick={() => {
                        deleteIdentity.mutate(identity.$id, {
                          onSuccess: () => {
                            toast.success('Identity has been deleted')
                          },
                          onError: (error: Error) => {
                            toast.error(
                              error.message || 'Failed to delete identity',
                            )
                          },
                        })
                      }}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        {total > DEFAULT_PAGE_SIZE && (
          <Pagination
            currentPage={page}
            totalItems={total}
            pageSize={DEFAULT_PAGE_SIZE}
            onPageChange={setPage}
            onPageSizeChange={() => {}}
          />
        )}
      </div>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete identities</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete {selectedIdentities.size} identit
              {selectedIdentities.size === 1 ? 'y' : 'ies'}? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleBulkDelete}
              disabled={deleteIdentity.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// ============================================================================
// TARGETS TAB
// ============================================================================

function TargetsTab({
  projectId,
  userId,
}: {
  projectId: string
  userId: string
}) {
  const [page, setPage] = useState(1)
  const [selectedTargets, setSelectedTargets] = useState<Set<string>>(new Set())
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteTarget = useDeleteUserTarget(projectId, userId)

  const { data, isLoading } = useUserTargets(
    projectId,
    userId,
    page - 1,
    DEFAULT_PAGE_SIZE,
  )

  const targets = data?.targets || []
  const total = data?.total || 0

  const handleBulkDelete = async () => {
    if (selectedTargets.size === 0) return

    const targetIds = Array.from(selectedTargets)
    try {
      await Promise.all(targetIds.map((id) => deleteTarget.mutateAsync(id)))
      toast.success(
        `${targetIds.length} target${targetIds.length === 1 ? '' : 's'} have been deleted`,
      )
      setSelectedTargets(new Set())
      setDeleteDialogOpen(false)
    } catch (error: any) {
      toast.error(error.message || 'Failed to delete targets')
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <>
      <div className="space-y-4">
        <div className="flex items-center justify-end">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setCreateDialogOpen(true)}
          >
            <Plus className="mr-1.5 h-3.5 w-3.5" />
            Create target
          </Button>
        </div>

        {selectedTargets.size > 0 && (
          <div className="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3">
            <span className="text-[13px] text-foreground">
              {selectedTargets.size} target
              {selectedTargets.size === 1 ? '' : 's'} selected
            </span>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteDialogOpen(true)}
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" />
              Delete selected
            </Button>
          </div>
        )}

        {targets.length === 0 ? (
          <div className="rounded-lg border border-border bg-card py-12">
            <EmptyState
              icon={Smartphone}
              title="No targets available"
              description="No messaging targets configured for this user."
              isEmpty={true}
              iconSize="md"
            />
            <div className="mt-4 text-center">
              <a
                href="https://appwrite.io/docs/users"
                className="text-[13px] text-primary hover:underline"
                target="_blank"
                rel="noopener noreferrer"
              >
                Learn more about targets
              </a>
            </div>
          </div>
        ) : (
          <>
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="w-[40px] px-4">
                      <Checkbox
                        checked={
                          targets.length > 0 &&
                          selectedTargets.size === targets.length
                        }
                        onCheckedChange={(checked) => {
                          if (checked) {
                            setSelectedTargets(
                              new Set(targets.map((t) => t.$id)),
                            )
                          } else {
                            setSelectedTargets(new Set())
                          }
                        }}
                      />
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Target ID
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Target
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Provider Type
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                      Created
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      Actions
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {targets.map((target) => (
                    <TableRow key={target.$id}>
                      <TableCell className="px-4 py-3">
                        <Checkbox
                          checked={selectedTargets.has(target.$id)}
                          onCheckedChange={(checked) => {
                            const newSelected = new Set(selectedTargets)
                            if (checked) {
                              newSelected.add(target.$id)
                            } else {
                              newSelected.delete(target.$id)
                            }
                            setSelectedTargets(newSelected)
                          }}
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <CopyableId id={target.$id} size="xs" />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-[13px]">
                        {target.name || target.identifier}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Badge variant="secondary" className="text-[12px]">
                          {target.providerType}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={new Date(target.$createdAt)}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0"
                          onClick={() => {
                            deleteTarget.mutate(target.$id, {
                              onSuccess: () => {
                                toast.success('Target has been deleted')
                              },
                              onError: (error: Error) => {
                                toast.error(
                                  error.message || 'Failed to delete target',
                                )
                              },
                            })
                          }}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {total > DEFAULT_PAGE_SIZE && (
              <Pagination
                currentPage={page}
                totalItems={total}
                pageSize={DEFAULT_PAGE_SIZE}
                onPageChange={setPage}
                onPageSizeChange={() => {}}
              />
            )}
          </>
        )}

        <CreateTargetDialog
          open={createDialogOpen}
          onOpenChange={setCreateDialogOpen}
          projectId={projectId}
          userId={userId}
        />
      </div>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete targets</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete {selectedTargets.size} target
              {selectedTargets.size === 1 ? '' : 's'}? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleBulkDelete}
              disabled={deleteTarget.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

// Create Target Dialog
function CreateTargetDialog({
  open,
  onOpenChange,
  projectId,
  userId,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  userId: string
}) {
  const [providerType, setProviderType] = useState<string>(
    MessagingProviderType.Push,
  )
  const [identifier, setIdentifier] = useState('')
  const [providerId, setProviderId] = useState('')
  const [name, setName] = useState('')
  const [targetId, setTargetId] = useState('')
  const [showCustomId, setShowCustomId] = useState(false)
  const createTarget = useCreateUserTarget(projectId, userId)

  useEffect(() => {
    if (!open) {
      setProviderType(MessagingProviderType.Push)
      setIdentifier('')
      setProviderId('')
      setName('')
      setTargetId('')
      setShowCustomId(false)
    }
  }, [open])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const targetData: any = {
      providerType,
      identifier,
    }

    if (providerType === MessagingProviderType.Push) {
      if (!providerId || !identifier || !name) {
        toast.error(
          'Provider ID, identifier, and name are required for push targets',
        )
        return
      }
      targetData.providerId = providerId
      targetData.name = name
    }

    if (showCustomId && targetId) {
      targetData.targetId = targetId
    }

    createTarget.mutate(targetData, {
      onSuccess: () => {
        toast.success('Target has been created')
        onOpenChange(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to create target')
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Create target</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Add a new messaging target for this user.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="provider-type" className="text-[12px]">
                Provider Type <span className="text-destructive">*</span>
              </Label>
              <Select value={providerType} onValueChange={setProviderType}>
                <SelectTrigger id="provider-type" className="h-9 text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={MessagingProviderType.Push}>
                    Push
                  </SelectItem>
                  <SelectItem value={MessagingProviderType.Email}>
                    Email
                  </SelectItem>
                  <SelectItem value={MessagingProviderType.Sms}>SMS</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {providerType === MessagingProviderType.Push && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="provider-id" className="text-[12px]">
                    Provider ID <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="provider-id"
                    value={providerId}
                    onChange={(e) => setProviderId(e.target.value)}
                    placeholder="Enter provider ID"
                    className="h-9 text-[13px]"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="name" className="text-[12px]">
                    Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Enter target name"
                    className="h-9 text-[13px]"
                    required
                  />
                </div>
              </>
            )}

            <div className="space-y-2">
              <Label htmlFor="identifier" className="text-[12px]">
                Identifier <span className="text-destructive">*</span>
              </Label>
              <Input
                id="identifier"
                type={
                  providerType === MessagingProviderType.Email
                    ? 'email'
                    : 'text'
                }
                value={identifier}
                onChange={(e) => setIdentifier(e.target.value)}
                placeholder={
                  providerType === MessagingProviderType.Push
                    ? 'Enter push token'
                    : providerType === MessagingProviderType.Email
                      ? 'Enter email address'
                      : 'Enter phone number'
                }
                className="h-9 text-[13px]"
                required
              />
            </div>

            <div className="flex items-center space-x-2">
              <Checkbox
                id="custom-id"
                checked={showCustomId}
                onCheckedChange={(checked) => setShowCustomId(checked === true)}
              />
              <Label htmlFor="custom-id" className="text-[13px] cursor-pointer">
                Use custom target ID
              </Label>
            </div>

            {showCustomId && (
              <div className="space-y-2">
                <Label htmlFor="target-id" className="text-[12px]">
                  Target ID
                </Label>
                <Input
                  id="target-id"
                  value={targetId}
                  onChange={(e) => setTargetId(e.target.value)}
                  placeholder="Enter custom target ID"
                  className="h-9 text-[13px] font-mono"
                  maxLength={36}
                />
              </div>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={createTarget.isPending}>
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ============================================================================
// SESSIONS TAB
// ============================================================================

function SessionsTab({
  projectId,
  userId,
  displayName,
}: {
  projectId: string
  userId: string
  displayName: string
}) {
  const { data, isLoading } = useUserSessions(projectId, userId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [sessionToDelete, setSessionToDelete] = useState<Models.Session | null>(
    null,
  )
  const deleteSession = useDeleteUserSession(projectId, userId)

  const sessions = data?.sessions || []
  const total = data?.total || 0

  const handleDelete = (session: Models.Session) => {
    setSessionToDelete(session)
    setDeleteDialogOpen(true)
  }

  const confirmDelete = () => {
    if (sessionToDelete) {
      deleteSession.mutate(sessionToDelete.$id, {
        onSuccess: () => {
          toast.success('Session has been deleted')
          setDeleteDialogOpen(false)
          setSessionToDelete(null)
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to delete session')
        },
      })
    }
  }


  const formatDeviceInfo = (session: Models.Session) => {
    const parts: string[] = []

    // Primary line: Client name and version
    if (session.clientName) {
      const clientInfo = session.clientVersion
        ? `${session.clientName} ${session.clientVersion}`
        : session.clientName
      parts.push(clientInfo)
    }

    // Secondary line: OS info
    const osInfo = session.osName
      ? session.osVersion
        ? `${session.osName} ${session.osVersion}`
        : session.osName
      : null

    // Device model info (if available)
    const deviceInfo =
      session.deviceBrand && session.deviceModel
        ? `${session.deviceBrand} ${session.deviceModel}`
        : session.deviceModel || session.deviceBrand || null

    return {
      primary: parts.length > 0 ? parts.join(' ') : 'Unknown device',
      secondary: osInfo || deviceInfo || null,
    }
  }

  const getProviderName = (provider?: string) => {
    if (!provider) return 'Unknown'
    const nameMap: Record<string, string> = {
      email: 'Email',
      phone: 'Phone',
      github: 'GitHub',
      google: 'Google',
      apple: 'Apple',
      facebook: 'Facebook',
      twitter: 'Twitter',
      microsoft: 'Microsoft',
      linkedin: 'LinkedIn',
      discord: 'Discord',
      twitch: 'Twitch',
      spotify: 'Spotify',
    }
    return nameMap[provider.toLowerCase()] || provider
  }

  const getProviderIcon = (provider?: string) => {
    if (!provider) return null
    const iconMap: Record<string, string> = {
      email: 'mail.svg',
      phone: 'phone.svg',
      github: 'github.svg',
      google: 'google.svg',
      apple: 'apple.svg',
      facebook: 'facebook.svg',
    }
    return iconMap[provider.toLowerCase()] || null
  }

  const getCountryFlagUrl = (countryCode?: string) => {
    if (!countryCode) return null
    return `${getBaseEndpoint()}/avatars/flags/${countryCode.toLowerCase()}?width=20&height=20&quality=100&project=console`
  }

  const formatIP = (ip?: string) => {
    if (!ip) return null
    // Trim IPv6 addresses if they're too long (show first 20 chars)
    if (ip.includes(':')) {
      return ip.length > 20 ? `${ip.substring(0, 20)}...` : ip
    }
    return ip
  }

  return (
    <>
      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[320px]">
                    Device & Auth
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    Location
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                    IP Address
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[160px]">
                    Created
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    Expires
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading ? (
                  <TableRow>
                    <TableCell colSpan={6} className="px-6 py-12">
                      <div className="text-center">
                        <p className="text-[13px] text-muted-foreground">
                          Loading sessions...
                        </p>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : sessions.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="px-6 py-12">
                      <div className="text-center">
                        <p className="text-[14px] font-medium text-foreground mb-1">
                          No active sessions
                        </p>
                        <p className="text-[13px] text-muted-foreground">
                          This user doesn't have any active sessions at the
                          moment.
                        </p>
                      </div>
                    </TableCell>
                  </TableRow>
                ) : (
                  sessions.map((session) => {
                    const deviceInfo = formatDeviceInfo(session)
                    const isCurrent = session.current || false
                    const flagUrl = getCountryFlagUrl(session.countryCode)
                    const providerIcon = getProviderIcon(session.provider)
                    const hasMFA = session.factors && session.factors.length > 0

                    return (
                      <TableRow key={session.$id} className="group">
                        <TableCell className="px-4 py-3.5">
                          <div className="flex items-start gap-3">
                            <BrowserIcon
                              clientCode={session.clientCode}
                              deviceName={session.deviceName}
                            />
                            <div className="flex-1 min-w-0 space-y-1">
                              <div className="flex items-center gap-2 flex-wrap">
                                <span className="text-[13px] font-semibold text-foreground">
                                  {deviceInfo.primary}
                                </span>
                                {isCurrent && (
                                  <Badge
                                    variant="success"
                                    className="text-[10px] font-medium px-1.5 py-0 h-4"
                                  >
                                    Current
                                  </Badge>
                                )}
                                {hasMFA && (
                                  <Tooltip>
                                    <TooltipTrigger asChild>
                                      <div className="flex items-center justify-center h-4 w-4 rounded bg-muted/50">
                                        <Shield className="h-2.5 w-2.5 text-muted-foreground" />
                                      </div>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                      <p className="text-xs">
                                        MFA: {session.factors?.join(', ')}
                                      </p>
                                    </TooltipContent>
                                  </Tooltip>
                                )}
                              </div>
                              <div className="flex items-center gap-3 flex-wrap">
                                {deviceInfo.secondary && (
                                  <span className="text-[12px] text-muted-foreground">
                                    {deviceInfo.secondary}
                                  </span>
                                )}
                                {session.provider && (
                                  <div className="flex items-center gap-1.5">
                                    {providerIcon ? (
                                      <img
                                        src={`/icons/${providerIcon}`}
                                        alt={session.provider}
                                        className="h-3 w-3 opacity-60"
                                        onError={(e) => {
                                          e.currentTarget.style.display = 'none'
                                        }}
                                      />
                                    ) : (
                                      <Key className="h-3 w-3 text-muted-foreground/60" />
                                    )}
                                    <span className="text-[11px] text-muted-foreground/80 font-medium">
                                      {getProviderName(session.provider)}
                                    </span>
                                  </div>
                                )}
                              </div>
                            </div>
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3.5">
                          <div className="flex items-center gap-2">
                            {flagUrl ? (
                              <img
                                src={flagUrl}
                                alt={session.countryName || ''}
                                className="h-4 w-4 rounded-sm border border-border/30 shadow-sm"
                                onError={(e) => {
                                  e.currentTarget.style.display = 'none'
                                }}
                              />
                            ) : (
                              <Globe className="h-4 w-4 text-muted-foreground/60" />
                            )}
                            <span className="text-[13px] font-medium text-foreground">
                              {session.countryName && session.countryCode && session.countryCode !== '--'
                                ? session.countryName
                                : 'Unknown'}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3.5">
                          {session.ip ? (
                            <code className="text-[12px] text-muted-foreground font-mono bg-muted/30 px-1.5 py-0.5 rounded">
                              {formatIP(session.ip)}
                            </code>
                          ) : (
                            <span className="text-[12px] text-muted-foreground/50">—</span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3.5">
                          <DateTooltip
                            date={session.$createdAt}
                            className="text-[12px] font-medium text-foreground"
                          />
                        </TableCell>
                        <TableCell className="px-4 py-3.5">
                          <DateTooltip
                            date={session.expire}
                            className="text-[12px] font-medium text-foreground"
                          />
                        </TableCell>
                        <TableCell className="px-4 py-3.5 text-right">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8 p-0"
                            onClick={() => handleDelete(session)}
                            disabled={deleteSession.isPending}
                            title="Revoke session"
                          >
                            <LogOut className="h-4 w-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    )
                  })
                )}
              </TableBody>
            </Table>
      </div>

      {/* Delete Session Confirmation Dialog */}
      <Dialog
        open={deleteDialogOpen}
        onOpenChange={(open) => {
          setDeleteDialogOpen(open)
          if (!open) {
            setSessionToDelete(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete session</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this session? This action cannot
              be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteSession.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteSession.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

    </>
  )
}

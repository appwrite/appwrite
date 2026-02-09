import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { sdk, getBaseEndpoint } from '@/lib/appwrite/sdk'
import { useAccountSessions } from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
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
} from '@/components/ui/dialog'
import {
  Globe,
  LogOut,
  Activity,
  Shield,
  Key,
  Smartphone,
  Tablet,
  Monitor,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Browser } from '@appwrite.io/console'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

// Dependencies for query invalidation
const Dependencies = {
  SESSIONS: ['sessions', 'account'],
} as const

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
      } catch {
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

export function AccountSessions() {
  const { data, isLoading } = useAccountSessions()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  // Check cache directly - data is prefetched in route loader, so it should be available
  // This matches the storage view pattern where data is guaranteed to be ready
  const cachedData = queryClient.getQueryData<{
    sessions: Models.Session[]
    total: number
  }>(['sessions', 'account'])

  // Data is prefetched in route loader, so it should be available when component renders
  // Use data from hook first, fallback to cache if hook hasn't hydrated yet
  const sessions = data?.sessions || cachedData?.sessions || []

  // Only show empty state if query has completed (not loading) and there are no sessions
  // This matches the storage pattern: check isLoading first, then show empty state if not loading and empty
  const sessionsLoading = isLoading && !data && !cachedData

  // All hooks must be called before any conditional returns (Rules of Hooks)
  const [logoutDialogOpen, setLogoutDialogOpen] = useState(false)
  const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false)
  const [sessionToDelete, setSessionToDelete] = useState<string | null>(null)
  const [isCurrentSession, setIsCurrentSession] = useState(false)

  const deleteSessionMutation = useMutation({
    mutationFn: async (sessionId: string) => {
      return await sdk.forConsole.account.deleteSession({ sessionId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.SESSIONS })
      toast.success('Session has been deleted')
      setLogoutDialogOpen(false)

      const wasCurrentSession = isCurrentSession
      setSessionToDelete(null)
      setIsCurrentSession(false)

      // Redirect to sign-in if current session was deleted
      if (wasCurrentSession) {
        navigate({ to: '/sign-in' })
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete session')
    },
  })

  const deleteAllSessionsMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.deleteSessions()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.SESSIONS })
      toast.success('All sessions have been deleted')
      setDeleteAllDialogOpen(false)
      // Redirect to sign-in when all sessions are deleted
      navigate({ to: '/sign-in' })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete sessions')
    },
  })

  const handleDeleteClick = (sessionId: string) => {
    const session = sessions.find((s) => s.$id === sessionId)
    setSessionToDelete(sessionId)
    setIsCurrentSession(session?.current || false)
    setLogoutDialogOpen(true)
  }

  const handleConfirmLogout = () => {
    if (sessionToDelete) {
      deleteSessionMutation.mutate(sessionToDelete)
    }
  }

  const handleDeleteAllClick = () => {
    setDeleteAllDialogOpen(true)
  }

  const handleConfirmDeleteAll = () => {
    deleteAllSessionsMutation.mutate()
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
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[15px] font-semibold text-foreground">
                Sessions
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                Manage your active sessions across different devices. You can
                revoke access from any device at any time.
              </p>
            </div>
            {sessions.length > 1 && (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleDeleteAllClick}
                disabled={deleteAllSessionsMutation.isPending}
              >
                Delete all sessions
              </Button>
            )}
          </div>
        </div>
        <div className="border-t border-border -mx-6" />
        <div className="px-6 py-4">
          <div className="rounded-lg border border-border overflow-hidden">
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
                {sessionsLoading ? (
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
                          You don't have any active sessions at the moment.
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
                              {session.countryName &&
                              session.countryCode &&
                              session.countryCode !== '--'
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
                            <span className="text-[12px] text-muted-foreground/50">
                              —
                            </span>
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
                            onClick={() => handleDeleteClick(session.$id)}
                            disabled={deleteSessionMutation.isPending}
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
        </div>
      </div>

      {/* Logout Confirmation Dialog */}
      <Dialog
        open={logoutDialogOpen}
        onOpenChange={(open) => {
          setLogoutDialogOpen(open)
          if (!open) {
            setSessionToDelete(null)
            setIsCurrentSession(false)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Logout from device</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {isCurrentSession
                ? 'Are you sure you want to logout from this device? You will be redirected to the sign-in page and will need to sign in again to access your account.'
                : 'Are you sure you want to logout from this device? You will need to sign in again to access your account from this device.'}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setLogoutDialogOpen(false)}
              disabled={deleteSessionMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmLogout}
              disabled={deleteSessionMutation.isPending}
            >
              Logout
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete All Sessions Confirmation Dialog */}
      <Dialog open={deleteAllDialogOpen} onOpenChange={setDeleteAllDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Logout from all devices</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to logout from all devices? You will need to
              sign in again to access your account from any device.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteAllDialogOpen(false)}
              disabled={deleteAllSessionsMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDeleteAll}
              disabled={deleteAllSessionsMutation.isPending}
            >
              Logout from all devices
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { sdk, getBaseEndpoint } from '@/lib/appwrite/sdk'
import { useAccountSessions, fetchAccountSessions } from '@/lib/react-query/hooks'
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
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { formatIpForDisplay } from '@/lib/format-ip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Browser } from '@appwrite.io/console'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

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
          <div className="absolute -bottom-0.5 -end-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-background ring-2 ring-background">
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
        <div className="absolute -bottom-0.5 -end-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-background ring-2 ring-background">
          <DeviceIcon className="h-2.5 w-2.5 text-muted-foreground" />
        </div>
      )}
    </div>
  )
}

export function AccountSessions({
  initialData,
}: {
  initialData?: Awaited<ReturnType<typeof fetchAccountSessions>>
} = {}) {
  const t = useT()
  const { data, isFetched } = useAccountSessions()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const sessions = data?.sessions ?? initialData?.sessions ?? []
  const hasResolvedData = isFetched || initialData !== undefined

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
      toast.success(t('Session has been deleted'))
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
      toast.error(error.message || t('Failed to delete session'))
    },
  })

  const deleteAllSessionsMutation = useMutation({
    mutationFn: async () => {
      return await sdk.forConsole.account.deleteSessions()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.SESSIONS })
      toast.success(t('All sessions have been deleted'))
      setDeleteAllDialogOpen(false)
      // Redirect to sign-in when all sessions are deleted
      navigate({ to: '/sign-in' })
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete sessions'))
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
      primary: parts.length > 0 ? parts.join(' ') : t('Unknown device'),
      secondary: osInfo || deviceInfo || null,
    }
  }

  const getProviderName = (provider?: string) => {
    if (!provider) return t('Unknown')
    const nameMap: Record<string, string> = {
      email: t('Email'),
      phone: t('Phone'),
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

  if (hasResolvedData && sessions.length === 0) {
    return (
      <EmptyState
        icon={Monitor}
        title={t('No active sessions')}
        description={t("You don't have any active sessions at the moment.")}
        isEmpty={true}
        variant="card"
        iconSize="md"
      />
    )
  }

  if (!hasResolvedData) {
    return null
  }

  return (
    <>
      <div>
        {sessions.length > 1 && (
          <div className="mb-4 flex justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDeleteAllClick}
              disabled={deleteAllSessionsMutation.isPending}
            >
              <LogOut className="me-1.5 h-4 w-4" />
              {t('Delete all sessions')}
            </Button>
          </div>
        )}
        <div className="rounded-lg border border-border bg-card overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[320px]">
                  {t('Device & Auth')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  {t('Location')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                  {t('IP Address')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[160px]">
                  {t('Created')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                  {t('Expires')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sessions.map((session) => {
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
                                className="text-[10px] font-medium shrink-0 px-1.5 py-0 h-4"
                              >
                                {t('Current')}
                              </Badge>
                            )}
                            {hasMFA && (
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Badge
                                    variant="info"
                                    className="h-4 shrink-0 gap-0.5 px-1 py-0 text-[10px] font-medium"
                                  >
                                    <Shield className="h-2.5 w-2.5" />
                                    MFA
                                  </Badge>
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
                              <Badge
                                variant="info"
                                className="text-[10px] shrink-0 gap-1.5 font-medium"
                              >
                                {providerIcon ? (
                                  <img
                                    src={`/icons/${providerIcon}`}
                                    alt={session.provider}
                                    className={`h-3 w-3 ${PUBLIC_ICON_MUTED_CLASSES}`}
                                    onError={(e) => {
                                      e.currentTarget.style.display = 'none'
                                    }}
                                  />
                                ) : (
                                  <Key className="h-3 w-3 opacity-70" />
                                )}
                                {getProviderName(session.provider)}
                              </Badge>
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
                            : t('Unknown')}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3.5">
                      {session.ip ? (
                        <code className="text-[12px] text-muted-foreground font-mono bg-muted/30 px-1.5 py-0.5 rounded">
                          {formatIpForDisplay(session.ip) ?? session.ip}
                        </code>
                      ) : (
                        <span className="text-[12px] text-muted-foreground/50">
                           - 
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
                    <TableCell className="px-4 py-3.5 text-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => handleDeleteClick(session.$id)}
                        disabled={deleteSessionMutation.isPending}
                        title={t('Revoke session')}
                        aria-label={t('Revoke session')}
                      >
                        <LogOut className="h-4 w-4" />
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Logout from device')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {isCurrentSession
                ? t(
                    'Are you sure you want to logout from this device? You will be redirected to the sign-in page and will need to sign in again to access your account.',
                  )
                : t(
                    'Are you sure you want to logout from this device? You will need to sign in again to access your account from this device.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setLogoutDialogOpen(false)}
              disabled={deleteSessionMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmLogout}
              disabled={deleteSessionMutation.isPending}
            >
              {t('Logout')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete All Sessions Confirmation Dialog */}
      <Dialog open={deleteAllDialogOpen} onOpenChange={setDeleteAllDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Logout from all devices')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to logout from all devices? You will need to sign in again to access your account from any device.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteAllDialogOpen(false)}
              disabled={deleteAllSessionsMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmDeleteAll}
              disabled={deleteAllSessionsMutation.isPending}
            >
              {t('Logout from all devices')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Trash2,
  Monitor,
  Smartphone,
  Tablet,
  Globe,
  LogOut,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { Skeleton } from '@/components/ui/skeleton'

// Dependencies for query invalidation
const Dependencies = {
  SESSIONS: ['sessions', 'account'],
} as const

export function AccountSessions() {
  const { data, isLoading } = useAccountSessions()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const sessions = data?.sessions || []
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

  const getDeviceIcon = (deviceName?: string) => {
    switch (deviceName?.toLowerCase()) {
      case 'smartphone':
        return Smartphone
      case 'tablet':
        return Tablet
      case 'desktop':
      default:
        return Monitor
    }
  }

  const formatDeviceInfo = (session: Models.Session) => {
    const parts: string[] = []

    if (session.clientName) {
      parts.push(session.clientName)
    }

    if (session.osName) {
      parts.push(session.osName)
    }

    return parts.length > 0 ? parts.join(' on ') : 'Unknown device'
  }

  const getCountryFlagUrl = (countryCode?: string) => {
    if (!countryCode) return null
    return `https://cloud.appwrite.io/v1/avatars/flags/${countryCode.toLowerCase()}?width=20&height=20&quality=100&project=console`
  }

  const formatIP = (ip?: string) => {
    if (!ip) return null
    // Trim IPv6 addresses if they're too long (show first 20 chars)
    if (ip.includes(':')) {
      return ip.length > 20 ? `${ip.substring(0, 20)}...` : ip
    }
    return ip
  }

  if (isLoading) {
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
            </div>
          </div>
          <div className="border-t border-border -mx-6" />
          <div className="px-6 py-4">
            <div className="rounded-lg border border-border overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[300px]">
                      Device
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                      Location
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                      Created At
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                      Expires At
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {Array.from({ length: 3 }).map((_, i) => (
                    <TableRow key={i}>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <Skeleton className="h-4 w-4 shrink-0 rounded" />
                          <Skeleton className="h-4 w-32" />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex flex-col gap-0.5">
                          <div className="flex items-center gap-2">
                            <Skeleton className="h-4 w-4 shrink-0 rounded" />
                            <Skeleton className="h-4 w-24" />
                          </div>
                          <Skeleton className="h-3 w-20 ml-6" />
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Skeleton className="h-4 w-28" />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Skeleton className="h-4 w-28" />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <Skeleton className="h-7 w-20 ml-auto" />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </div>
        </div>
      </div>
    )
  }

  if (sessions.length === 0) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Sessions
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="rounded-lg border border-border bg-muted/30 p-6 text-center">
              <p className="text-[14px] font-medium text-foreground mb-1">
                No active sessions
              </p>
              <p className="text-[13px] text-muted-foreground">
                You don't have any active sessions at the moment.
              </p>
            </div>
          </div>
        </div>
      </div>
    )
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
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[300px]">
                    Device
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                    Location
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    Created At
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    Expires At
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sessions.map((session) => {
                  const DeviceIcon = getDeviceIcon(session.deviceName)
                  const deviceInfo = formatDeviceInfo(session)
                  const isCurrent = session.current || false
                  const flagUrl = getCountryFlagUrl(session.countryCode)

                  return (
                    <TableRow key={session.$id}>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <DeviceIcon className="h-4 w-4 text-muted-foreground" />
                          <div className="flex items-center gap-2">
                            <span className="text-[13px] font-medium">
                              {deviceInfo}
                            </span>
                            {isCurrent && (
                              <span className="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium bg-green-500/10 text-green-600 dark:text-green-400">
                                Current
                              </span>
                            )}
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex flex-col gap-0.5">
                          <div className="flex items-center gap-2">
                            {flagUrl ? (
                              <img
                                src={flagUrl}
                                alt={session.countryName || ''}
                                className="h-4 w-4 rounded border border-border/50"
                                onError={(e) => {
                                  e.currentTarget.style.display = 'none'
                                }}
                              />
                            ) : (
                              <Globe className="h-4 w-4 text-muted-foreground" />
                            )}
                            <span className="text-[13px] text-muted-foreground">
                              {session.countryName || '-'}
                            </span>
                          </div>
                          {session.ip && (
                            <span className="text-[12px] text-muted-foreground font-mono pl-6">
                              {formatIP(session.ip)}
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={session.$createdAt}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={session.expire}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 text-[13px]"
                          onClick={() => handleDeleteClick(session.$id)}
                          disabled={deleteSessionMutation.isPending}
                          title="Logout from this device"
                        >
                          <LogOut className="h-4 w-4 mr-1.5" />
                          Logout
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
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

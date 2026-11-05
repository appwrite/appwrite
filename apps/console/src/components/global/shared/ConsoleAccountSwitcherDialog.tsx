import { useEffect, useMemo, useState } from 'react'
import { Check, Loader2, Plus, UserRound } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useConsoleDevice } from '@/lib/react-query/hooks/console-devices'
import {
  ADD_ACCOUNT_SEARCH_PARAM,
  ensureConsoleDevice,
  getConsoleAccountLabel,
  rememberConsoleAccountLabel,
  signOutConsoleDevice,
  switchConsoleDeviceSession,
} from '@/lib/console-devices'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type ConsoleAccountSwitcherDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
}

type AccountRow = {
  sessionId: string
  userId: string
  name: string
  email: string
  active: boolean
}

function hardNavigate(path: string) {
  if (typeof window === 'undefined') return
  window.location.assign(path)
}

/**
 * Operator-only account switcher: keep several console accounts signed in on one
 * browser and switch the active one, backed by the account switching device.
 * Modeled on the impersonation dialog and gated by the same operator flag.
 */
export function ConsoleAccountSwitcherDialog({
  open,
  onOpenChange,
}: ConsoleAccountSwitcherDialogProps) {
  const t = useT()
  const { account: rawAccount } = useAuth()
  const account = rawAccount as OperatorAccount | undefined
  const isOperator = isOperatorAccount(account)

  const [pending, setPending] = useState<string | null>(null)

  // Cache the active account's label so the switcher can name it among the device's
  // sessions, which only carry user ids.
  useEffect(() => {
    if (open) rememberConsoleAccountLabel(account)
  }, [open, account])

  const {
    data: device,
    isLoading,
    error,
  } = useConsoleDevice(open && isOperator)

  const rows = useMemo<AccountRow[]>(() => {
    const sessions = device?.sessions ?? []
    const activeSessionId = device?.activeSessionId ?? ''
    return sessions.map((session: Models.Session) => {
      const label = getConsoleAccountLabel(session.userId)
      const isActiveAccount = account?.$id === session.userId
      return {
        sessionId: session.$id,
        userId: session.userId,
        name: label?.name || (isActiveAccount ? (account?.name ?? '') : ''),
        email: label?.email || (isActiveAccount ? (account?.email ?? '') : ''),
        active: session.$id === activeSessionId,
      }
    })
  }, [device, account])

  const handleSwitch = async (row: AccountRow) => {
    if (pending) return
    setPending(row.sessionId)
    try {
      await switchConsoleDeviceSession(row.sessionId)
      hardNavigate('/account')
    } catch (switchError) {
      setPending(null)
      toast.error(getErrorMessage(switchError, t('Could not switch account.')))
    }
  }

  const handleAddAccount = async () => {
    if (pending) return
    setPending('add')
    try {
      // The device cookie must exist before sign-in so the new session joins it
      // instead of being refused for an already-active session.
      await ensureConsoleDevice()
      hardNavigate(`/sign-in?${ADD_ACCOUNT_SEARCH_PARAM}=1`)
    } catch (addError) {
      setPending(null)
      toast.error(getErrorMessage(addError, t('Could not add an account.')))
    }
  }

  const handleSignOutAll = async () => {
    if (pending) return
    setPending('signout')
    try {
      await signOutConsoleDevice()
      hardNavigate('/sign-in')
    } catch (signOutError) {
      setPending(null)
      toast.error(
        getErrorMessage(signOutError, t('Could not sign out of all accounts.')),
      )
    }
  }

  if (!isOperator) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 pe-12 text-start">
          <Badge variant="info" className="mb-2 w-fit text-[10px]">
            <UserRound className="size-3" aria-hidden />
            {t('Operator access')}
          </Badge>
          <DialogTitle>{t('Switch account')}</DialogTitle>
          <DialogDescription className="mt-2 text-[13px] leading-relaxed">
            {t(
              'Stay signed in to several Console accounts on this browser and switch the active one. Signing in another account adds it here instead of replacing this one.',
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-6 pb-6">
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
            </div>
          ) : error ? (
            <p className="py-6 text-center text-[13px] text-destructive">
              {getErrorMessage(error, t('Could not load your accounts.'))}
            </p>
          ) : rows.length === 0 ? (
            <p className="py-6 text-center text-[13px] text-muted-foreground">
              {t('No accounts are signed in on this browser yet.')}
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {rows.map((row) => (
                <li
                  key={row.sessionId}
                  className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-3"
                >
                  <PhotoAvatar
                    userId={row.userId}
                    name={row.name || row.email || row.userId}
                    size="sm"
                    className="shrink-0"
                  />
                  <div className="min-w-0 flex-1 text-start">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {row.name || row.email || row.userId}
                    </p>
                    {row.email && row.name ? (
                      <p className="truncate text-[12px] text-muted-foreground">
                        {row.email}
                      </p>
                    ) : null}
                  </div>
                  {row.active ? (
                    <Badge variant="secondary" className="shrink-0 gap-1">
                      <Check className="size-3" aria-hidden />
                      {t('Active')}
                    </Badge>
                  ) : (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="shrink-0"
                      onClick={() => void handleSwitch(row)}
                      disabled={!!pending}
                    >
                      {pending === row.sessionId ? (
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                      ) : (
                        t('Switch')
                      )}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}

          <div className="mt-1 flex flex-col gap-2">
            <Button
              type="button"
              variant="outline"
              className="w-full gap-1.5"
              onClick={() => void handleAddAccount()}
              disabled={!!pending}
            >
              {pending === 'add' ? (
                <Loader2 className="size-4 animate-spin" aria-hidden />
              ) : (
                <Plus className="size-4" aria-hidden />
              )}
              {t('Add another account')}
            </Button>
            <Button
              type="button"
              variant="ghost"
              className="w-full text-destructive hover:text-destructive"
              onClick={() => void handleSignOutAll()}
              disabled={!!pending}
            >
              {pending === 'signout' ? (
                <Loader2 className="size-4 animate-spin" aria-hidden />
              ) : null}
              {t('Sign out of all accounts')}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

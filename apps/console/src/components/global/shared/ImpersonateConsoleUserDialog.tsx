import { useState, useEffect, useMemo } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Users, Loader2 } from 'lucide-react'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  readConsoleImpersonationOperatorSnapshot,
  resolveConsoleImpersonationOperator,
} from '@/lib/console-impersonation'
import { performExitConsoleImpersonation } from '@/lib/console-impersonation-exit'
import {
  beginConsoleImpersonation,
  type ConsoleImpersonationTarget,
} from '@/lib/console-impersonation-start'
import {
  consoleUsersByIdQueryOptions,
  consoleUsersImpersonationSearchQueryOptions,
} from '@/lib/react-query/hooks/console-user-search'
import {
  mergeRecentImpersonationLists,
  parseRecentImpersonationUsers,
  readRecentImpersonationSavedList,
  readRecentImpersonationSessionList,
  writeRecentImpersonationDetails,
  type RecentImpersonationUser,
} from '@/lib/user-prefs-keys'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ConsoleAccount = Models.User & {
  impersonator?: boolean
  impersonatorUserId?: string
}

export function ImpersonateConsoleUserDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const { account: accountRaw } = useAuth()
  const account = accountRaw as ConsoleAccount | undefined
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const canImpersonate =
    account?.impersonator === true || !!account?.impersonatorUserId

  const isImpersonating = !!account?.impersonatorUserId

  const { data, isFetching } = useQuery({
    ...consoleUsersImpersonationSearchQueryOptions(debouncedSearch),
    enabled: open && canImpersonate,
    placeholderData: keepPreviousData,
  })

  const users = data?.users ?? []

  const operatorSnapshot = readConsoleImpersonationOperatorSnapshot()
  const operatorId = isImpersonating ? operatorSnapshot?.$id : account?.$id
  const [startingUserId, setStartingUserId] = useState<string | null>(null)

  /**
   * Unsaved picks lead. Operator prefs are unreadable while impersonating, so the
   * list this tab last saved to them stands in until impersonation ends.
   */
  const storedRecentUsers = useMemo((): RecentImpersonationUser[] => {
    if (!open || !operatorId?.trim()) return []
    const saved = isImpersonating
      ? readRecentImpersonationSavedList(operatorId)
      : parseRecentImpersonationUsers(
          (account as { prefs?: Record<string, unknown> } | undefined)?.prefs,
          operatorId,
        )
    return mergeRecentImpersonationLists(
      readRecentImpersonationSessionList(operatorId),
      saved,
    )
  }, [account, isImpersonating, open, operatorId])

  // Names and emails are cached per browser; look up the ones this browser lacks.
  const unlabeledRecentIds = useMemo(
    () =>
      storedRecentUsers.filter((u) => !u.name && !u.email).map((u) => u.$id),
    [storedRecentUsers],
  )
  const { data: unlabeledRecentData } = useQuery({
    ...consoleUsersByIdQueryOptions(unlabeledRecentIds),
    enabled: open && canImpersonate && unlabeledRecentIds.length > 0,
  })

  useEffect(() => {
    if (!operatorId || !unlabeledRecentData?.users.length) return
    writeRecentImpersonationDetails(operatorId, unlabeledRecentData.users)
  }, [operatorId, unlabeledRecentData])

  const recentImpersonationUsers = useMemo((): RecentImpersonationUser[] => {
    const fetched = new Map(
      (unlabeledRecentData?.users ?? []).map((u) => [u.$id, u]),
    )
    return storedRecentUsers.map((recent) => {
      const user = fetched.get(recent.$id)
      if (!user) return recent
      return {
        $id: recent.$id,
        name: user.name || undefined,
        email: user.email || undefined,
      }
    })
  }, [storedRecentUsers, unlabeledRecentData])

  const showRecentSection =
    !debouncedSearch.trim() && recentImpersonationUsers.length > 0

  const handleSelectUser = async (user: ConsoleImpersonationTarget) => {
    if (startingUserId) return
    const targetId = user?.$id
    if (!targetId?.trim()) {
      toast.error(t('This user has no valid ID; pick another user.'))
      return
    }

    if (user.$id === account?.$id) {
      toast.error(t('That user is already the active Console session.'))
      return
    }
    if (operatorId && user.$id === operatorId) {
      toast.error(t('You cannot impersonate your own operator account.'))
      return
    }

    const operator = resolveConsoleImpersonationOperator(account)

    if (!operator) {
      if (isImpersonating) {
        toast.error(
          t('Operator context was lost. Stop impersonating, then start again.'),
        )
      }
      return
    }

    setStartingUserId(targetId)
    try {
      await beginConsoleImpersonation(user, operator)
    } catch (e) {
      setStartingUserId(null)
      console.error(e)
      const message =
        e instanceof AppwriteException
          ? e.message
          : e instanceof Error
            ? e.message
            : t('Could not start impersonation.')
      toast.error(message)
    }
  }

  const handleStop = async () => {
    onOpenChange(false)
    await performExitConsoleImpersonation()
  }

  if (!canImpersonate) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 pe-12 text-start">
          <DialogTitle>{t('Impersonate user')}</DialogTitle>
          <DialogDescription className="mt-2 text-[13px] leading-relaxed">
            {t(
              "Matches the start of name, email, phone, or user ID. The Console runs with the selected account's access until you end impersonation.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="flex h-[72px] shrink-0 flex-col justify-center border-t border-border bg-muted/30 px-6">
          {isImpersonating ? (
            <>
              <p className="truncate text-[12px] text-muted-foreground">
                {t('Active session:')}{' '}
                <span className="font-medium text-foreground">
                  {account?.name || account?.email || account?.$id}
                </span>
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-2 h-8 w-fit text-[12px]"
                onClick={() => void handleStop()}
              >
                {t('Exit impersonation')}
              </Button>
            </>
          ) : (
            <p className="text-[12px] leading-relaxed text-muted-foreground">
              {t(
                'Pick a console user to operate as. Your operator account stays signed in.',
              )}
            </p>
          )}
        </div>

        <Command
          shouldFilter={false}
          className="min-h-0 overflow-hidden rounded-none border-t border-border"
        >
          <div className="relative shrink-0">
            <CommandInput
              placeholder={t('Name, email, phone, or user ID…')}
              value={search}
              onValueChange={setSearch}
              className="h-9 pe-9 text-[13px]"
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity',
                isFetching || startingUserId ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList className="h-[360px] min-h-[360px] max-h-[360px] overflow-x-hidden overflow-y-scroll overscroll-contain [scrollbar-gutter:stable]">
            {showRecentSection && (
              <CommandGroup heading={t('Recent')}>
                {recentImpersonationUsers.map((recent) => {
                  const disabled =
                    recent.$id === account?.$id ||
                    (!!operatorId && recent.$id === operatorId) ||
                    !!startingUserId
                  const label = recent.name || recent.email || recent.$id
                  const cmdkValue = [
                    recent.$id,
                    recent.name,
                    recent.email,
                    'recent',
                  ]
                    .filter(Boolean)
                    .join(' ')
                  return (
                    <CommandItem
                      key={`recent-${recent.$id}`}
                      value={cmdkValue}
                      disabled={disabled}
                      onSelect={() => void handleSelectUser(recent)}
                      className="cursor-pointer gap-2 px-3 py-2.5 aria-disabled:opacity-50"
                    >
                      <PhotoAvatar
                        userId={recent.$id}
                        name={label}
                        size="sm"
                        className="shrink-0"
                      />
                      <div className="min-w-0 flex-1 text-start">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {recent.name || '-'}
                        </p>
                        <p className="truncate text-[12px] text-muted-foreground">
                          {recent.email || '-'}
                        </p>
                        <div
                          className="mt-1"
                          onClick={(e) => e.stopPropagation()}
                          onPointerDown={(e) => e.stopPropagation()}
                        >
                          <CopyableId
                            id={recent.$id}
                            size="xs"
                            maxWidth={200}
                            className="max-w-full"
                          />
                        </div>
                      </div>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            )}
            {showRecentSection && users.length > 0 && (
              <CommandSeparator className="mx-0" />
            )}
            <CommandEmpty className="p-0">
              {isFetching ? (
                <div className="flex h-[360px] items-center justify-center px-6">
                  <Loader2
                    className="h-5 w-5 animate-spin text-muted-foreground"
                    aria-hidden
                  />
                  <span className="sr-only">{t('Loading users')}</span>
                </div>
              ) : (
                <div className="flex h-[360px] items-center justify-center">
                  <EmptyState
                    icon={Users}
                    iconSize="sm"
                    variant="default"
                    className="px-6 py-0"
                    title={
                      debouncedSearch.trim()
                        ? t('No matching users')
                        : t('No users to show')
                    }
                    description={
                      debouncedSearch.trim()
                        ? t(
                            'Try a different prefix for name, email, phone, or user ID.',
                          )
                        : t(
                            'Enter text that matches the start of a name, email, phone, or user ID.',
                          )
                    }
                  />
                </div>
              )}
            </CommandEmpty>
            <CommandGroup>
              {users.map((user) => {
                const disabled =
                  user.$id === account?.$id ||
                  (!!operatorId && user.$id === operatorId) ||
                  !!startingUserId
                const label = user.name || user.email || user.$id
                const cmdkValue = [user.$id, user.name, user.email]
                  .filter(Boolean)
                  .join(' ')
                return (
                  <CommandItem
                    key={user.$id}
                    value={cmdkValue}
                    disabled={disabled}
                    onSelect={() => void handleSelectUser(user)}
                    className="cursor-pointer gap-2 px-3 py-2.5 aria-disabled:opacity-50"
                  >
                    <PhotoAvatar
                      userId={user.$id}
                      name={label}
                      size="sm"
                      className="shrink-0"
                    />
                    <div className="min-w-0 flex-1 text-start">
                      <p className="truncate text-[13px] font-medium text-foreground">
                        {user.name || '-'}
                      </p>
                      <p className="truncate text-[12px] text-muted-foreground">
                        {user.email || '-'}
                      </p>
                      <div
                        className="mt-1"
                        onClick={(e) => e.stopPropagation()}
                        onPointerDown={(e) => e.stopPropagation()}
                      >
                        <CopyableId
                          id={user.$id}
                          size="xs"
                          maxWidth={200}
                          className="max-w-full"
                        />
                      </div>
                    </div>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  )
}

import { useState, useEffect, useMemo } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Eye, Users, Loader2 } from 'lucide-react'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
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
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  applyConsoleImpersonateUserId,
  clearConsoleImpersonateUser,
} from '@/lib/appwrite/sdk'
import {
  clearConsoleImpersonationSession,
  hardNavigateToAccountAfterImpersonation,
  persistConsoleImpersonationSession,
  readConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'
import { flushRecentImpersonationUsersToAccountPrefs } from '@/lib/react-query/hooks/auth'
import { consoleUsersImpersonationSearchQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import {
  appendRecentImpersonationUser,
  mergeRecentImpersonationLists,
  parseRecentImpersonationUsers,
  readRecentImpersonationSessionList,
  writeRecentImpersonationSessionList,
  type RecentImpersonationUser,
} from '@/lib/user-prefs-keys'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ConsoleAccount = Models.User & {
  impersonator?: boolean
  impersonatorUserId?: string
}

function recentImpersonationUserToModel(
  r: RecentImpersonationUser,
): Models.User {
  return {
    $id: r.$id,
    name: r.name ?? '',
    email: r.email ?? '',
  } as Models.User
}

export function ImpersonateConsoleUserPopover() {
  const t = useT()
  const { account: accountRaw } = useAuth()
  const account = accountRaw as ConsoleAccount | undefined
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const showTrigger =
    account?.impersonator === true || !!account?.impersonatorUserId

  const isImpersonating = !!account?.impersonatorUserId

  const { data, isFetching } = useQuery({
    ...consoleUsersImpersonationSearchQueryOptions(debouncedSearch),
    enabled: open,
    placeholderData: keepPreviousData,
  })

  const users = data?.users ?? []

  const operatorSnapshot = readConsoleImpersonationOperatorSnapshot()
  const operatorId = isImpersonating ? operatorSnapshot?.$id : account?.$id

  const recentImpersonationUsers = useMemo((): RecentImpersonationUser[] => {
    if (!operatorId?.trim()) return []
    if (isImpersonating) {
      return readRecentImpersonationSessionList(operatorId)
    }
    const fromPrefs = parseRecentImpersonationUsers(
      (account as { prefs?: Record<string, unknown> } | undefined)?.prefs,
      operatorId,
    )
    const fromSession = readRecentImpersonationSessionList(operatorId)
    return mergeRecentImpersonationLists(fromPrefs, fromSession)
  }, [account, isImpersonating, operatorId])

  const showRecentSection =
    !debouncedSearch.trim() && recentImpersonationUsers.length > 0

  /**
   * Recent targets are session-only while impersonating (and on start).
   * Operator account prefs get ID references on exit via
   * `flushRecentImpersonationUsersToAccountPrefs`; name/email stay in localStorage.
   */
  const persistRecentImpersonation = (user: Models.User) => {
    if (!operatorId?.trim()) return
    const currentList = isImpersonating
      ? readRecentImpersonationSessionList(operatorId)
      : mergeRecentImpersonationLists(
          parseRecentImpersonationUsers(
            (account as { prefs?: Record<string, unknown> } | undefined)?.prefs,
            operatorId,
          ),
          readRecentImpersonationSessionList(operatorId),
        )
    const next = appendRecentImpersonationUser(currentList, user)
    writeRecentImpersonationSessionList(operatorId, next)
  }

  const handleSelectUser = (user: Models.User) => {
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

    let operator: { $id: string; name: string; email: string } | undefined

    if (isImpersonating) {
      operator = readConsoleImpersonationOperatorSnapshot()
      if (!operator) {
        toast.error(
          t('Operator context was lost. Stop impersonating, then start again.'),
        )
        return
      }
    } else if (account) {
      operator = {
        $id: account.$id,
        name: account.name ?? '',
        email: account.email ?? '',
      }
    }

    if (!operator) return

    try {
      persistRecentImpersonation(user)
      applyConsoleImpersonateUserId(targetId)
      persistConsoleImpersonationSession(targetId, operator, {
        skipNotify: true,
      })
      hardNavigateToAccountAfterImpersonation()
    } catch (e) {
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
    const opId = readConsoleImpersonationOperatorSnapshot()?.$id
    clearConsoleImpersonateUser()
    clearConsoleImpersonationSession({ skipNotify: true })
    setOpen(false)
    if (opId) {
      void flushRecentImpersonationUsersToAccountPrefs(opId).catch((e) => {
        console.error(e)
      })
    }
    hardNavigateToAccountAfterImpersonation()
  }

  if (!showTrigger) return null

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <Tooltip>
        {/* Avoid nested asChild (Tooltip+Popover) - React 19 composeRefs can loop (#185). */}
        <TooltipTrigger asChild>
          <span className="hidden h-9 w-9 shrink-0 @[900px]:inline-flex">
            <PopoverTrigger asChild>
              <button
                type="button"
                className="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label={
                  isImpersonating ? t('Impersonating') : t('Impersonate')
                }
              >
                <Eye className="h-4 w-4" />
              </button>
            </PopoverTrigger>
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{isImpersonating ? t('Impersonating') : t('Impersonate')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent
        align="end"
        className="w-[min(100vw-2rem,380px)] p-0"
      >
        <div className="border-b border-border px-4 py-3">
          <h3 className="text-[13px] font-semibold text-foreground">
            {t('Impersonate user')}
          </h3>
          <p className="mt-1.5 text-[12px] leading-relaxed text-muted-foreground">
            {t(
              "Matches the start of name, email, phone, or user ID. The Console runs with the selected account's access until you end impersonation.",
            )}
          </p>
        </div>

        {isImpersonating && (
          <div className="border-b border-border bg-muted/30 px-4 py-3">
            <p className="text-[12px] text-muted-foreground">
              {t('Active session:')}{' '}
              <span className="font-medium text-foreground">
                {account?.name || account?.email || account?.$id}
              </span>
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-2 h-8 text-[12px]"
              onClick={() => void handleStop()}
            >
              {t('Exit impersonation')}
            </Button>
          </div>
        )}

        <Command shouldFilter={false} className="overflow-visible">
          <div className="relative border-b border-border">
            <CommandInput
              placeholder={t('Name, email, phone, or user ID…')}
              value={search}
              onValueChange={setSearch}
              className={cn('h-9 text-[13px]', isFetching && 'pe-9')}
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList className="min-h-[180px] max-h-[280px]">
            {showRecentSection && (
              <CommandGroup heading={t('Recent')}>
                {recentImpersonationUsers.map((recent) => {
                  const disabled =
                    recent.$id === account?.$id ||
                    (!!operatorId && recent.$id === operatorId)
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
                      onSelect={() =>
                        handleSelectUser(
                          recentImpersonationUserToModel(recent),
                        )
                      }
                      className="cursor-pointer gap-2 px-3 py-2.5 aria-disabled:opacity-50"
                    >
                      <InitialsAvatar
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
                <div className="flex items-center justify-center px-6 py-10">
                  <Loader2
                    className="h-5 w-5 animate-spin text-muted-foreground"
                    aria-hidden
                  />
                  <span className="sr-only">{t('Loading users')}</span>
                </div>
              ) : (
                <EmptyState
                  icon={Users}
                  iconSize="sm"
                  variant="default"
                  className="px-6 py-8"
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
              )}
            </CommandEmpty>
            <CommandGroup>
              {users.map((user) => {
                const disabled =
                  user.$id === account?.$id ||
                  (!!operatorId && user.$id === operatorId)
                const label = user.name || user.email || user.$id
                const cmdkValue = [user.$id, user.name, user.email]
                  .filter(Boolean)
                  .join(' ')
                return (
                  <CommandItem
                    key={user.$id}
                    value={cmdkValue}
                    disabled={disabled}
                    onSelect={() => handleSelectUser(user)}
                    className="cursor-pointer gap-2 px-3 py-2.5 aria-disabled:opacity-50"
                  >
                    <InitialsAvatar
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
      </PopoverContent>
    </Popover>
  )
}

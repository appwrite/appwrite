import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Users } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  MESSAGING_TARGET_PICKER_PAGE_SIZE,
  messagingTargetPickerUsersQueryOptions,
} from '@/lib/react-query/hooks/messaging'

function filterTargetsForProvider(
  user: Models.User,
  providerType: string | null | undefined,
): Models.Target[] {
  const targets = user.targets || []
  if (!providerType) return targets
  return targets.filter((t) => t.providerType === providerType)
}

function providerLabel(type: string): string {
  if (type === 'email') return 'Email'
  if (type === 'sms') return 'SMS'
  if (type === 'push') return 'Push'
  return type
}

export type MessagingTargetsModalProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  projectId: string | undefined
  /** When set, only targets matching this provider are selectable. Omit for all targets (e.g. topic subscribers). */
  providerType?: string | null
  /** Current selection (target id → target). Undefined values are skipped. */
  initialSelectedById: Record<string, Models.Target | undefined>
  onConfirm: (selectedById: Record<string, Models.Target>) => void
}

export function MessagingTargetsModal({
  open,
  onOpenChange,
  title,
  description,
  projectId,
  providerType,
  initialSelectedById,
  onConfirm,
}: MessagingTargetsModalProps) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedById, setSelectedById] = useState<Record<string, Models.Target>>(
    {},
  )
  const [openUsers, setOpenUsers] = useState<Record<string, boolean>>({})

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const pageIndexed = page - 1

  const { data, isLoading } = useQuery(
    messagingTargetPickerUsersQueryOptions(
      projectId,
      pageIndexed,
      MESSAGING_TARGET_PICKER_PAGE_SIZE,
      debouncedSearch,
      providerType ?? null,
    ),
  )

  const users = data?.users ?? []
  const total = data?.total ?? 0

  useEffect(() => {
    if (!open) return
    const next: Record<string, Models.Target> = {}
    for (const [id, t] of Object.entries(initialSelectedById)) {
      if (t) next[id] = t
    }
    setSelectedById(next)
  }, [open, initialSelectedById])

  useEffect(() => {
    if (!open) {
      setSearch('')
      setDebouncedSearch('')
      setPage(1)
      setOpenUsers({})
    }
  }, [open])

  const usersWithTargets = useMemo(() => {
    return users
      .map((u) => ({
        user: u,
        targets: filterTargetsForProvider(u, providerType),
      }))
      .filter((row) => row.targets.length > 0)
  }, [users, providerType])

  const toggleTarget = (target: Models.Target, checked: boolean) => {
    setSelectedById((prev) => {
      const next = { ...prev }
      if (checked) {
        next[target.$id] = target
      } else {
        delete next[target.$id]
      }
      return next
    })
  }

  const toggleUserRow = (user: Models.User, checked: boolean) => {
    const targets = filterTargetsForProvider(user, providerType)
    setSelectedById((prev) => {
      const next = { ...prev }
      for (const t of targets) {
        if (checked) next[t.$id] = t
        else delete next[t.$id]
      }
      return next
    })
  }

  const userCheckboxState = (user: Models.User) => {
    const targets = filterTargetsForProvider(user, providerType)
    if (targets.length === 0) return { checked: false as const, disabled: true }
    let selected = 0
    for (const t of targets) {
      if (selectedById[t.$id]) selected++
    }
    if (selected === 0) return { checked: false as const, disabled: false }
    if (selected === targets.length) return { checked: true as const, disabled: false }
    return { checked: 'indeterminate' as const, disabled: false }
  }

  const handleConfirm = () => {
    onConfirm({ ...selectedById })
    onOpenChange(false)
  }

  const handleCancel = () => {
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[80dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {description}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0 flex-1 min-h-0 flex flex-col gap-4">
          <Input
            placeholder={t('Search by name, email, phone or ID...')}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            className="h-9"
          />

          <div className="flex-1 min-h-0 overflow-y-auto space-y-2">
            {isLoading ? (
              <div className="text-center py-8 text-sm text-muted-foreground">
                {t('Loading users…')}
              </div>
            ) : usersWithTargets.length === 0 ? (
              <EmptyState
                icon={Users}
                isEmpty={!debouncedSearch}
                hasFilters={!!debouncedSearch}
                className="py-8"
              />
            ) : (
              usersWithTargets.map(({ user, targets }) => {
                const rowState = userCheckboxState(user)
                const isOpen = openUsers[user.$id] ?? false
                return (
                  <Collapsible
                    key={user.$id}
                    open={isOpen}
                    onOpenChange={(o) =>
                      setOpenUsers((s) => ({ ...s, [user.$id]: o }))
                    }
                    className="rounded-lg border border-border bg-card"
                  >
                    <div className="flex items-center gap-2 px-3 py-2">
                      <Checkbox
                        checked={
                          rowState.checked === 'indeterminate'
                            ? 'indeterminate'
                            : rowState.checked
                        }
                        disabled={rowState.disabled}
                        onCheckedChange={(v) => {
                          const on = v === true || v === 'indeterminate'
                          toggleUserRow(user, on)
                        }}
                        onClick={(e) => e.stopPropagation()}
                      />
                      <CollapsibleTrigger className="flex flex-1 items-center gap-2 text-start min-w-0">
                        {isOpen ? (
                          <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
                        ) : (
                          <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                        )}
                        <span className="truncate text-[13px] font-medium text-foreground">
                          {user.name || user.email || user.phone || user.$id}
                        </span>
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {targets.filter((t) => selectedById[t.$id]).length}/
                          {targets.length}
                        </Badge>
                      </CollapsibleTrigger>
                    </div>
                    <CollapsibleContent>
                      <div className="border-t border-border px-3 py-2 space-y-2">
                        {targets.map((target) => (
                          <div
                            key={target.$id}
                            className="flex items-start gap-2 ps-6"
                          >
                            <Checkbox
                              checked={!!selectedById[target.$id]}
                              onCheckedChange={(v) =>
                                toggleTarget(target, v === true)
                              }
                              className="mt-0.5"
                            />
                            <div className="flex flex-wrap items-center gap-2 min-w-0">
                              <Badge
                                variant="info"
                                className="text-[10px] shrink-0"
                              >
                                {t(providerLabel(target.providerType))}
                              </Badge>
                              <span className="text-[13px] text-foreground break-all">
                                {target.providerType === 'push'
                                  ? target.name || target.identifier
                                  : target.identifier}
                              </span>
                            </div>
                          </div>
                        ))}
                      </div>
                    </CollapsibleContent>
                  </Collapsible>
                )
              })
            )}
          </div>

          {total > MESSAGING_TARGET_PICKER_PAGE_SIZE ? (
            <Pagination
              className="pt-1"
              currentPage={page}
              totalItems={total}
              pageSize={MESSAGING_TARGET_PICKER_PAGE_SIZE}
              onPageChange={setPage}
              onPageSizeChange={() => {}}
              showPageSizeSelector={false}
              itemLabel={t('users')}
            />
          ) : null}
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={handleCancel}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleConfirm}>{t('Save selection')}</Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

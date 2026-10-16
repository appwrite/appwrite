import { useEffect, useState } from 'react'
import { Users } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useProjectUsers } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export function MessagingRecipientUsersModal({
  open,
  onOpenChange,
  projectId,
  existingUserIds,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string | undefined
  existingUserIds: Set<string>
  onConfirm: (userIds: string[]) => void
}) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [selectedUserIds, setSelectedUserIds] = useState<Set<string>>(new Set())
  const [page, setPage] = useState(0)
  const pageSize = 25

  const { users, isLoading } = useProjectUsers(
    projectId || null,
    page,
    pageSize,
    search,
  )

  useEffect(() => {
    if (!open) {
      setSelectedUserIds(new Set())
      setSearch('')
      setPage(0)
    }
  }, [open])

  const toggle = (userId: string) => {
    setSelectedUserIds((prev) => {
      const next = new Set(prev)
      if (next.has(userId)) next.delete(userId)
      else next.add(userId)
      return next
    })
  }

  const handleAdd = () => {
    const picked = Array.from(selectedUserIds)
    if (picked.length === 0) return
    const merged = new Set([...existingUserIds, ...picked])
    onConfirm(Array.from(merged))
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0 max-h-[80dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add users')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Users receive this message on every target matching the message channel for their account.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 flex-1 min-h-0 flex flex-col gap-3">
          <Input
            placeholder={t('Search users by name, email, or ID...')}
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(0)
            }}
            className="h-9"
          />
          <div className="flex-1 min-h-0 overflow-y-auto space-y-1">
            {isLoading ? (
              <div className="py-8 text-center text-[13px] text-muted-foreground">
                {t('Loading users…')}
              </div>
            ) : users.length === 0 ? (
              <EmptyState
                icon={Users}
                isEmpty={!search}
                hasFilters={!!search}
                className="py-8"
              />
            ) : (
              users.map((user) => {
                const disabled = existingUserIds.has(user.$id)
                const isSelected = selectedUserIds.has(user.$id)
                const label = user.name || user.email || user.phone || user.$id
                return (
                  <div
                    key={user.$id}
                    className={cn(
                      'flex items-center gap-3 rounded-lg border p-3',
                      disabled
                        ? 'cursor-not-allowed border-border bg-muted/30 opacity-50'
                        : 'cursor-pointer border-border hover:bg-muted/50',
                      !disabled && isSelected && 'border-primary bg-primary/5',
                    )}
                    onClick={() => !disabled && toggle(user.$id)}
                  >
                    <Checkbox
                      checked={isSelected}
                      disabled={disabled}
                      onCheckedChange={() => !disabled && toggle(user.$id)}
                      onClick={(e) => e.stopPropagation()}
                    />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[13px] font-medium text-foreground">
                        {label}
                      </p>
                      {user.email ? (
                        <p className="truncate text-[12px] text-muted-foreground">
                          {user.email}
                        </p>
                      ) : null}
                    </div>
                  </div>
                )
              })
            )}
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button onClick={handleAdd} disabled={selectedUserIds.size === 0}>
            {t('Add')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

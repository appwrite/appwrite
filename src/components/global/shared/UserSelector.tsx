/**
 * User Selector
 *
 * Form dropdown over the shared ResourceSearchPopover (same list as the
 * Auth user detail title switcher).
 */

import { useEffect, useMemo, useState } from 'react'
import { ChevronDown, User } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  ResourceSearchPopover,
  type ResourceSearchListItem,
} from '@/components/global/shared/ResourceSearchPopover'
import { fetchUser } from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'

export interface UserSelectorProps {
  projectId: string | null | undefined
  value: string
  onValueChange: (userId: string) => void
  placeholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
  /** Hide users that are already selected elsewhere (e.g. existing team members). */
  excludeIds?: ReadonlySet<string> | readonly string[]
}

export function UserSelector({
  projectId,
  value,
  onValueChange,
  placeholder = 'Select a user',
  disabled = false,
  triggerClassName,
  contentClassName,
  excludeIds,
}: UserSelectorProps) {
  const t = useT()
  const [selectedSnapshot, setSelectedSnapshot] =
    useState<ResourceSearchListItem | null>(null)

  useEffect(() => {
    if (!value) {
      setSelectedSnapshot(null)
      return
    }
    if (selectedSnapshot && selectedSnapshot.id !== value) {
      setSelectedSnapshot(null)
    }
  }, [value, selectedSnapshot])

  const { data: selectedUser } = useQuery({
    queryKey: ['user', 'project', projectId, value],
    queryFn: () => fetchUser(projectId!, value),
    enabled: !!projectId && !!value,
    staleTime: DEFAULT_STALE_TIME,
  })

  const selectedLabel =
    selectedUser?.name ||
    selectedUser?.email ||
    selectedUser?.phone ||
    (selectedSnapshot?.id === value ? selectedSnapshot.label : undefined) ||
    ''
  const selectedInitials =
    selectedUser?.name ||
    selectedUser?.email ||
    selectedUser?.phone ||
    (selectedSnapshot?.id === value
      ? selectedSnapshot.initialsName || selectedSnapshot.label
      : undefined) ||
    selectedLabel

  const pinnedItems = useMemo((): ResourceSearchListItem[] => {
    if (selectedUser) {
      return [
        {
          id: selectedUser.$id,
          label:
            selectedUser.name ||
            selectedUser.email ||
            selectedUser.phone ||
            selectedUser.$id,
          initialsName:
            selectedUser.name ||
            selectedUser.email ||
            selectedUser.phone ||
            undefined,
        },
      ]
    }
    if (selectedSnapshot?.id === value) return [selectedSnapshot]
    return []
  }, [selectedUser, selectedSnapshot, value])

  return (
    <ResourceSearchPopover
      kind="user"
      projectId={projectId}
      selectedId={value}
      onSelect={(id, item) => {
        setSelectedSnapshot(item)
        onValueChange(id)
      }}
      excludeIds={excludeIds}
      pinnedItems={pinnedItems}
      disabled={disabled || !projectId}
      prefetch
      className="w-full min-w-0"
      contentClassName={contentClassName}
      trigger={
        <Button
          type="button"
          variant="outline"
          role="combobox"
          disabled={disabled || !projectId}
          className={cn(
            'h-9 w-full justify-between gap-2 text-[13px] font-normal',
            !value && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-2 truncate">
            {value && selectedLabel ? (
              <InitialsAvatar
                name={selectedInitials || selectedLabel}
                size="xs"
                className="shrink-0"
              />
            ) : (
              <User className="h-4 w-4 shrink-0 text-muted-foreground" />
            )}
            <span className="truncate">
              {value && selectedLabel ? selectedLabel : t(placeholder)}
            </span>
          </span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      }
    />
  )
}

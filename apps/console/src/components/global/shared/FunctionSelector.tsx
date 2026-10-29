/**
 * Function Selector
 *
 * Form dropdown over the shared ResourceSearchPopover (same list as the
 * function detail title switcher).
 */

import { useEffect, useMemo, useState } from 'react'
import { ChevronDown, Terminal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  ResourceSearchPopover,
  type ResourceSearchListItem,
} from '@/components/global/shared/ResourceSearchPopover'
import { projectFunctionQueryOptions } from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export interface FunctionSelectorProps {
  projectId: string | null | undefined
  value: string
  onValueChange: (functionId: string) => void
  placeholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

export function FunctionSelector({
  projectId,
  value,
  onValueChange,
  placeholder = 'Select function',
  disabled = false,
  triggerClassName,
  contentClassName,
}: FunctionSelectorProps) {
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

  const { data: selectedFunction } = useQuery({
    ...projectFunctionQueryOptions(projectId, value || undefined),
    enabled: !!projectId && !!value,
  })

  const pinnedItems = useMemo((): ResourceSearchListItem[] => {
    if (selectedFunction) {
      return [
        {
          id: selectedFunction.$id,
          label: selectedFunction.name || 'Unnamed function',
          runtime: selectedFunction.runtime,
        },
      ]
    }
    if (selectedSnapshot?.id === value) return [selectedSnapshot]
    return []
  }, [selectedFunction, selectedSnapshot, value])

  const selectedLabel =
    selectedFunction?.name ||
    (selectedSnapshot?.id === value ? selectedSnapshot.label : undefined) ||
    ''
  const selectedRuntime =
    selectedFunction?.runtime ??
    (selectedSnapshot?.id === value ? selectedSnapshot.runtime : undefined) ??
    ''

  return (
    <ResourceSearchPopover
      kind="function"
      projectId={projectId}
      selectedId={value}
      onSelect={(id, item) => {
        setSelectedSnapshot(item)
        onValueChange(id)
      }}
      pinnedItems={pinnedItems}
      disabled={disabled || !projectId}
      className="w-full min-w-0"
      contentClassName={cn(
        'w-[var(--radix-popover-trigger-width)] min-w-[240px]',
        contentClassName,
      )}
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
              <RuntimeIcon
                runtime={selectedRuntime}
                size="sm"
                className="h-4 w-4 shrink-0 text-muted-foreground"
              />
            ) : (
              <Terminal className="h-4 w-4 shrink-0 text-muted-foreground" />
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

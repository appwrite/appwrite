/**
 * Site Selector
 *
 * Form dropdown over the shared ResourceSearchPopover (same list as the
 * site detail title switcher).
 */

import { useEffect, useMemo, useState } from 'react'
import { ChevronDown, Globe } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ResourceSearchPopover,
  type ResourceSearchListItem,
} from '@/components/global/shared/ResourceSearchPopover'
import { siteQueryOptions } from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

function getSiteFramework(site: {
  buildFramework?: string
  buildFrameworkId?: string
  framework?: string
}): string | undefined {
  return site.buildFramework || site.buildFrameworkId || site.framework
}

export interface SiteSelectorProps {
  projectId: string | null | undefined
  value: string
  onValueChange: (siteId: string) => void
  placeholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
}

export function SiteSelector({
  projectId,
  value,
  onValueChange,
  placeholder = 'Select site',
  disabled = false,
  triggerClassName,
  contentClassName,
}: SiteSelectorProps) {
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

  const { data: selectedSite } = useQuery({
    ...siteQueryOptions(projectId, value || undefined),
    enabled: !!projectId && !!value,
  })

  const selectedFramework = selectedSite
    ? getSiteFramework(
        selectedSite as {
          buildFramework?: string
          buildFrameworkId?: string
          framework?: string
        },
      )
    : selectedSnapshot?.id === value
      ? selectedSnapshot.framework
      : undefined

  const pinnedItems = useMemo((): ResourceSearchListItem[] => {
    if (selectedSite) {
      return [
        {
          id: selectedSite.$id,
          label: selectedSite.name || 'Unnamed site',
          framework: getSiteFramework(
            selectedSite as {
              buildFramework?: string
              buildFrameworkId?: string
              framework?: string
            },
          ),
        },
      ]
    }
    if (selectedSnapshot?.id === value) return [selectedSnapshot]
    return []
  }, [selectedSite, selectedSnapshot, value])

  const selectedLabel =
    selectedSite?.name ||
    (selectedSnapshot?.id === value ? selectedSnapshot.label : undefined) ||
    ''

  return (
    <ResourceSearchPopover
      kind="site"
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
              <FrameworkIcon
                framework={selectedFramework}
                size="sm"
                className="h-4 w-4 shrink-0"
              />
            ) : (
              <Globe className="h-4 w-4 shrink-0 text-muted-foreground" />
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

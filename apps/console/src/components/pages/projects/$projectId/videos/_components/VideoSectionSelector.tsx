/**
 * Mobile droplist for the video detail sections. Shown when the secondary
 * sidebar is hidden (lg breakpoint), matching `DatabaseSectionSelector`.
 */

import { Fragment, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Check, ChevronDown, SquareArrowOutUpRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
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
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  getVideoTabDisabledReason,
  VIDEO_INSPECTOR_ACTION,
  VIDEO_TAB_GROUP_LABELS,
  videoTabPath,
  type VideoTab,
  type VideoTabDefinition,
  type VideoTabGroup,
} from './video-tabs'

const ITEM_CLASSNAME =
  'flex w-full items-center gap-1.5 rounded-sm px-2 py-1.5 text-start text-[13px] outline-none transition-colors'

export function VideoSectionSelector({
  projectId,
  videoId,
  tabs,
  groups,
  value,
  renditions,
  onOpenInspector,
  triggerClassName,
}: {
  projectId: string
  videoId: string
  tabs: VideoTabDefinition[]
  groups: VideoTabGroup[]
  value: VideoTab
  renditions: Models.VideoRendition[]
  onOpenInspector?: () => void
  triggerClassName?: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const selected = tabs.find((tab) => tab.id === value) ?? tabs[0]
  const SelectedIcon = selected?.icon

  const select = (tab: VideoTab) => {
    setOpen(false)
    void navigate({ to: videoTabPath(tab), params: { projectId, videoId } })
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          aria-label={t('Video sections')}
          className={cn(
            'h-8 w-full min-w-0 justify-between gap-1.5 text-[13px] font-normal',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-1.5">
            {SelectedIcon ? (
              <SelectedIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
            ) : null}
            <span className="truncate">{selected ? t(selected.label) : ''}</span>
          </span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="min-w-[var(--radix-popover-trigger-width)] max-w-[320px] p-1"
        align="start"
      >
        <div className="flex flex-col gap-0.5">
          {groups.map((group, groupIndex) => {
            const groupTabs = tabs.filter((tab) => tab.group === group)
            if (groupTabs.length === 0) return null
            const heading = VIDEO_TAB_GROUP_LABELS[group]
            return (
              <Fragment key={group}>
                {groupIndex > 0 ? (
                  <div className="-mx-1 my-1 h-px bg-border" />
                ) : null}
                {heading ? (
                  <p className="px-2 pb-0.5 pt-1 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                    {t(heading)}
                  </p>
                ) : null}
                {groupTabs.map((tab) => {
                  const Icon = tab.icon
                  const isActive = tab.id === value
                  const disabledReason = getVideoTabDisabledReason(tab.id, renditions)
                  const item = (
                    <button
                      key={tab.id}
                      type="button"
                      disabled={!!disabledReason}
                      onClick={() => select(tab.id)}
                      className={cn(
                        ITEM_CLASSNAME,
                        disabledReason
                          ? 'cursor-not-allowed opacity-50'
                          : 'cursor-pointer hover:bg-accent hover:text-accent-foreground',
                        isActive && !disabledReason && 'bg-accent/60',
                      )}
                    >
                      <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate">{t(tab.label)}</span>
                      {isActive ? (
                        <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      ) : null}
                    </button>
                  )
                  if (!disabledReason) return item
                  return (
                    <Tooltip key={tab.id}>
                      <TooltipTrigger asChild>
                        <span className="w-full">{item}</span>
                      </TooltipTrigger>
                      <TooltipContent side="top" className="max-w-xs text-[12px]">
                        {t(disabledReason)}
                      </TooltipContent>
                    </Tooltip>
                  )
                })}
                {group === VIDEO_INSPECTOR_ACTION.group && onOpenInspector ? (
                  <button
                    type="button"
                    onClick={() => {
                      setOpen(false)
                      onOpenInspector()
                    }}
                    className={cn(
                      ITEM_CLASSNAME,
                      'cursor-pointer hover:bg-accent hover:text-accent-foreground',
                    )}
                  >
                    <VIDEO_INSPECTOR_ACTION.icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate">
                      {t(VIDEO_INSPECTOR_ACTION.label)}
                    </span>
                    <SquareArrowOutUpRight className="h-3 w-3 shrink-0 text-muted-foreground" />
                  </button>
                ) : null}
              </Fragment>
            )
          })}
        </div>
      </PopoverContent>
    </Popover>
  )
}

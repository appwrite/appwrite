import type { ReactNode } from 'react'
import { Bookmark, Braces, Loader2, Play } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

type SqlEditorActionBarProps = {
  canSave: boolean
  canFormat: boolean
  canRun: boolean
  isRunning: boolean
  onSave: () => void
  onFormat: () => void
  onRun: () => void
}

function ActionDivider() {
  return <div className="mx-0.5 h-5 w-px shrink-0 bg-border/80" aria-hidden />
}

function DisabledActionTooltip({
  disabled,
  reason,
  children,
}: {
  disabled: boolean
  reason?: string
  children: ReactNode
}) {
  if (!disabled || !reason) return <>{children}</>

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{children}</span>
      </TooltipTrigger>
      <TooltipContent side="top" sideOffset={6} className="text-[12px]">
        {reason}
      </TooltipContent>
    </Tooltip>
  )
}

export function SqlEditorActionBar({
  canSave,
  canFormat,
  canRun,
  isRunning,
  onSave,
  onFormat,
  onRun,
}: SqlEditorActionBarProps) {
  return (
    <div className="pointer-events-none absolute inset-x-0 bottom-0 z-10 flex justify-center px-4 pb-4">
      <div
        className={cn(
          'pointer-events-auto flex items-center gap-0.5 rounded-xl border border-border/80',
          'bg-background/92 p-1 shadow-lg shadow-black/[0.06] backdrop-blur-md',
          'ring-1 ring-black/[0.04] dark:bg-background/88 dark:shadow-black/20 dark:ring-white/[0.06]',
        )}
      >
        <DisabledActionTooltip
          disabled={!canSave}
          reason="Write SQL before saving a query."
        >
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[12px] font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            onClick={onSave}
            disabled={!canSave}
          >
            <Bookmark className="h-3.5 w-3.5 shrink-0" />
            Save
          </Button>
        </DisabledActionTooltip>

        <ActionDivider />

        <DisabledActionTooltip
          disabled={!canFormat}
          reason="Write SQL before formatting."
        >
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[12px] font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            onClick={onFormat}
            disabled={!canFormat}
          >
            <Braces className="h-3.5 w-3.5 shrink-0" />
            Format
          </Button>
        </DisabledActionTooltip>

        <ActionDivider />

        <DisabledActionTooltip
          disabled={!canRun}
          reason={
            isRunning
              ? 'Query is running.'
              : 'Write SQL before running a query.'
          }
        >
          <Button
            type="button"
            variant="brandCta"
            size="sm"
            className="h-8 gap-1.5 px-3 text-[12px] font-semibold shadow-sm"
            onClick={onRun}
            disabled={!canRun}
          >
            {isRunning ? (
              <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
            ) : (
              <Play className="h-3.5 w-3.5 shrink-0 fill-current" />
            )}
            Run query
          </Button>
        </DisabledActionTooltip>
      </div>
    </div>
  )
}

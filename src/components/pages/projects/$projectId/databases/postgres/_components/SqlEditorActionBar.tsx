import type { ReactNode } from 'react'
import { Bookmark, Braces, Loader2, Play, Redo2, Undo2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { POSTGRES_SQL_EDITOR_SURFACE_CLASS } from './postgres-chrome'

type SqlEditorActionBarProps = {
  canUndo: boolean
  canRedo: boolean
  canSave: boolean
  canFormat: boolean
  canRun: boolean
  isRunning: boolean
  onUndo: () => void
  onRedo: () => void
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
  canUndo,
  canRedo,
  canSave,
  canFormat,
  canRun,
  isRunning,
  onUndo,
  onRedo,
  onSave,
  onFormat,
  onRun,
}: SqlEditorActionBarProps) {
  return (
    <div
      className={cn(
        'shrink-0 border-t border-border px-4 py-2.5 sm:px-6',
        POSTGRES_SQL_EDITOR_SURFACE_CLASS,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-0.5">
          <DisabledActionTooltip
            disabled={!canUndo}
            reason="Nothing to undo."
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onMouseDown={(event) => event.preventDefault()}
              onClick={onUndo}
              disabled={!canUndo}
              aria-label="Undo"
            >
              <Undo2 className="h-3.5 w-3.5 shrink-0" />
            </Button>
          </DisabledActionTooltip>

          <DisabledActionTooltip
            disabled={!canRedo}
            reason="Nothing to redo."
          >
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 text-muted-foreground hover:bg-muted/70 hover:text-foreground"
              onMouseDown={(event) => event.preventDefault()}
              onClick={onRedo}
              disabled={!canRedo}
              aria-label="Redo"
            >
              <Redo2 className="h-3.5 w-3.5 shrink-0" />
            </Button>
          </DisabledActionTooltip>
        </div>

        <div className={cn('flex items-center gap-0.5')}>
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
    </div>
  )
}

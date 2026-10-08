import {
  PanelLeft,
  PanelLeftClose,
  PanelRight,
  PanelRightClose,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

type GeneratorCanvasPanelTogglesProps = {
  leftOpen: boolean
  rightOpen: boolean
  onToggleLeft: () => void
  onToggleRight: () => void
  leftLabel?: string
  rightLabel?: string
  showLeftToggle?: boolean
  className?: string
}

export function GeneratorCanvasPanelToggles({
  leftOpen,
  rightOpen,
  onToggleLeft,
  onToggleRight,
  leftLabel = 'Toggle templates panel',
  rightLabel = 'Toggle properties panel',
  showLeftToggle = true,
  className,
}: GeneratorCanvasPanelTogglesProps) {
  return (
    <div className={cn('flex shrink-0 items-center gap-2', className)}>
      {showLeftToggle ? (
        <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-7 w-7 p-0"
        onClick={onToggleLeft}
        aria-label={leftOpen ? 'Hide left panel' : 'Show left panel'}
        aria-pressed={leftOpen}
        title={leftOpen ? 'Hide left panel' : 'Show left panel'}
      >
        {leftOpen ? (
          <PanelLeftClose className="h-4 w-4" />
        ) : (
          <PanelLeft className="h-4 w-4" />
        )}
        <span className="sr-only">{leftLabel}</span>
      </Button>
      ) : null}
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-7 w-7 p-0"
        onClick={onToggleRight}
        aria-label={rightOpen ? 'Hide right panel' : 'Show right panel'}
        aria-pressed={rightOpen}
        title={rightOpen ? 'Hide right panel' : 'Show right panel'}
      >
        {rightOpen ? (
          <PanelRightClose className="h-4 w-4" />
        ) : (
          <PanelRight className="h-4 w-4" />
        )}
        <span className="sr-only">{rightLabel}</span>
      </Button>
    </div>
  )
}

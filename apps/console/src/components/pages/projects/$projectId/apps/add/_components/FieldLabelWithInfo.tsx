import { Info } from 'lucide-react'
import type { ReactNode } from 'react'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type Props = {
  htmlFor: string
  children: ReactNode
  /** Tooltip body. When omitted the info icon is not rendered. */
  tooltip?: ReactNode
  /** Marks the field as required by appending a destructive-coloured asterisk. */
  required?: boolean
  className?: string
}

/**
 * Form field label paired with an optional info-icon tooltip. Mirrors the
 * `<Tooltip slot="info">` pattern used in the legacy Svelte console so the
 * stage 2 fields surface the same per-variant help text.
 */
export function FieldLabelWithInfo({
  htmlFor,
  children,
  tooltip,
  required,
  className,
}: Props) {
  const t = useT()
  return (
    <Label
      htmlFor={htmlFor}
      className={cn('flex items-center gap-1.5 text-[12px] font-medium', className)}
    >
      <span>
        {children}
        {required ? <span className="ms-1 text-destructive">*</span> : null}
      </span>
      {tooltip ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              aria-label={t('More information')}
              className="inline-flex h-4 w-4 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
            >
              <Info className="h-3.5 w-3.5" aria-hidden />
            </button>
          </TooltipTrigger>
          <TooltipContent
            side="top"
            sideOffset={6}
            className="z-[10050] max-w-[260px] text-[12px]"
          >
            {tooltip}
          </TooltipContent>
        </Tooltip>
      ) : null}
    </Label>
  )
}

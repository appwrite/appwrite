import type { ReactNode } from 'react'
import { Info } from 'lucide-react'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export const START_COMMAND_FIELD_TOOLTIP =
  'Shell command that starts your SSR app after deploy (for example, npm run start). If left empty, your framework default is used. This field is optional.'

type StartCommandLabelProps = {
  htmlFor: string
  /** Renders on the same row as the label (e.g. Reset in build settings). */
  trailing?: ReactNode
  className?: string
}

export function StartCommandLabel({
  htmlFor,
  trailing,
  className,
}: StartCommandLabelProps) {
  const t = useT()
  const label = (
    <Label
      htmlFor={htmlFor}
      className={cn('text-[13px] flex w-full items-center gap-1.5', className)}
    >
      <span>
        {t('Start command')}
        <span className="font-normal text-muted-foreground">
          {' '}
          {t('(optional)')}
        </span>
      </span>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className="inline-flex text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
            aria-label={t('About start command (optional)')}
          >
            <Info className="h-3.5 w-3.5" />
          </button>
        </TooltipTrigger>
        <TooltipContent
          side="top"
          className="max-w-[260px] z-[200] text-[12px]"
        >
          {t(START_COMMAND_FIELD_TOOLTIP)}
        </TooltipContent>
      </Tooltip>
    </Label>
  )

  if (trailing) {
    return (
      <div className="flex w-full items-center justify-between gap-2">
        {label}
        {trailing}
      </div>
    )
  }

  return <div className="w-full">{label}</div>
}

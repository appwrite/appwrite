import { Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import { USAGE_HISTORIC_DATA_NOTE } from '@/lib/usage/usage-chart-copy'
import { useT } from '@/lib/i18n/translate'

type UsageHistoricDataNoteProps = {
  className?: string
  variant?: 'inline' | 'footer'
}

export function UsageHistoricDataNote({
  className,
  variant = 'inline',
}: UsageHistoricDataNoteProps) {
  const t = useT()
  const content = (
    <p className="text-[12px] leading-relaxed text-muted-foreground">
      <Info
        className="mb-0.5 me-1.5 inline-block h-3.5 w-3.5 align-middle"
        aria-hidden
      />
      {t(USAGE_HISTORIC_DATA_NOTE)}
    </p>
  )

  if (variant === 'footer') {
    return (
      <div
        className={cn(
          'border-t border-border bg-muted/30 px-5 py-3',
          className,
        )}
      >
        {content}
      </div>
    )
  }

  return <div className={className}>{content}</div>
}

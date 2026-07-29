import { PlatformIcon } from '@/components/global/shared/Icon'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_PLATFORMS,
  ANALYTICS_PLATFORM_META,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'

type Props = {
  value: AnalyticsPlatform
  onChange: (platform: AnalyticsPlatform) => void
  disabled?: boolean
}

export function PlatformCards({ value, onChange, disabled }: Props) {
  const t = useT()

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
      {ANALYTICS_PLATFORMS.map((id) => {
        const meta = ANALYTICS_PLATFORM_META[id]
        const selected = value === id
        return (
          <button
            key={id}
            type="button"
            disabled={disabled}
            onClick={() => onChange(id)}
            className={cn(
              'flex w-full min-w-0 cursor-pointer items-start gap-3 rounded-lg border border-border bg-card/50 p-4 text-start transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              selected
                ? 'border-primary bg-card ring-1 ring-primary/30'
                : 'hover:bg-card',
              disabled && 'pointer-events-none cursor-not-allowed opacity-50',
            )}
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <PlatformIcon platform={meta.iconSlug} size="md" />
            </div>
            <span className="min-w-0">
              <span className="flex items-center gap-1.5">
                <span className="text-[13px] font-medium leading-snug text-foreground">
                  {t(meta.label)}
                </span>
                {meta.unreleased && (
                  <Badge variant="warning" className="text-[10px] shrink-0">
                    {t('SDK coming soon')}
                  </Badge>
                )}
              </span>
              <span className="mt-0.5 block text-[12px] leading-snug text-muted-foreground">
                {t(meta.description)}
              </span>
            </span>
          </button>
        )
      })}
    </div>
  )
}

import { Braces, Check } from 'lucide-react'
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

/**
 * Platform picker: three equal cards, content stacked (icon row, title,
 * description) so the cards stay readable at a third of a narrow form and
 * the "SDK coming soon" badge never collides with the title.
 */
export function PlatformCards({ value, onChange, disabled }: Props) {
  const t = useT()

  return (
    <div
      role="radiogroup"
      aria-label={t('Platform')}
      className="grid grid-cols-1 gap-3 sm:grid-cols-3"
    >
      {ANALYTICS_PLATFORMS.map((id) => {
        const meta = ANALYTICS_PLATFORM_META[id]
        const selected = value === id
        return (
          <button
            key={id}
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={() => onChange(id)}
            className={cn(
              'flex w-full min-w-0 cursor-pointer flex-col rounded-lg border bg-card/50 p-4 text-start transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              selected
                ? 'border-foreground/40 bg-card ring-1 ring-foreground/20'
                : 'border-border hover:bg-card',
              disabled && 'pointer-events-none cursor-not-allowed opacity-50',
            )}
          >
            <span className="flex items-center justify-between gap-2">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                {/* REST has no platform logo; a neutral glyph reads better
                    than reusing the JS one. */}
                {id === 'rest' ? (
                  <Braces className="h-4 w-4" />
                ) : (
                  <PlatformIcon platform={meta.iconSlug} size="md" />
                )}
              </span>
              <span
                className={cn(
                  'flex h-4 w-4 shrink-0 items-center justify-center rounded-full border',
                  selected
                    ? 'border-foreground bg-foreground text-background'
                    : 'border-border',
                )}
                aria-hidden
              >
                {selected ? <Check className="h-2.5 w-2.5" strokeWidth={3} /> : null}
              </span>
            </span>
            <span className="mt-3 text-[13px] font-medium text-foreground">
              {t(meta.label)}
            </span>
            <span className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
              {t(meta.description)}
            </span>
            {meta.unreleased ? (
              <Badge variant="warning" className="mt-3 w-fit text-[10px]">
                {t('SDK coming soon')}
              </Badge>
            ) : null}
          </button>
        )
      })}
    </div>
  )
}

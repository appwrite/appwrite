import type { ReactNode } from 'react'
import { Bot, Braces, Check, Smartphone } from 'lucide-react'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_PLATFORM_META,
  type AnalyticsPlatform,
} from '@/lib/analytics-wizard/snippets'
import { AnalyticsPropertyType } from '@appwrite.io/console'
import {
  ANALYTICS_PROPERTY_TYPES,
  ANALYTICS_PROPERTY_TYPE_META,
} from '@/lib/analytics-wizard/property-types'

type CardOption<T extends string> = {
  id: T
  label: string
  description: string
  icon: ReactNode
}

type CardsProps<T extends string> = {
  label: string
  options: CardOption<T>[]
  value: T
  onChange: (value: T) => void
  disabled?: boolean
}

/**
 * Radio cards, content stacked (icon row, title, description) so they stay
 * readable at a third of a narrow form.
 */
function OptionCards<T extends string>({
  label,
  options,
  value,
  onChange,
  disabled,
}: CardsProps<T>) {
  const t = useT()

  return (
    <div
      role="radiogroup"
      aria-label={t(label)}
      className={cn(
        'grid grid-cols-1 gap-3',
        options.length > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
      )}
    >
      {options.map((option) => {
        const selected = value === option.id
        return (
          <button
            key={option.id}
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={() => onChange(option.id)}
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
                {option.icon}
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
                {selected ? (
                  <Check className="h-2.5 w-2.5" strokeWidth={3} />
                ) : null}
              </span>
            </span>
            <span className="mt-3 text-[13px] font-medium text-foreground">
              {t(option.label)}
            </span>
            <span className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
              {t(option.description)}
            </span>
          </button>
        )
      })}
    </div>
  )
}

const TYPE_ICONS: Record<AnalyticsPropertyType, ReactNode> = {
  [AnalyticsPropertyType.Web]: <PlatformIcon platform="web" size="md" />,
  [AnalyticsPropertyType.Mobile]: <Smartphone className="h-4 w-4" />,
  [AnalyticsPropertyType.Agent]: <Bot className="h-4 w-4" />,
}

export function TypeCards({
  value,
  onChange,
  disabled,
}: {
  value: AnalyticsPropertyType
  onChange: (type: AnalyticsPropertyType) => void
  disabled?: boolean
}) {
  return (
    <OptionCards
      label="Type"
      options={ANALYTICS_PROPERTY_TYPES.map((id) => ({
        ...ANALYTICS_PROPERTY_TYPE_META[id],
        icon: TYPE_ICONS[id],
      }))}
      value={value}
      onChange={onChange}
      disabled={disabled}
    />
  )
}

export function PlatformCards({
  platforms,
  value,
  onChange,
  disabled,
}: {
  platforms: readonly AnalyticsPlatform[]
  value: AnalyticsPlatform
  onChange: (platform: AnalyticsPlatform) => void
  disabled?: boolean
}) {
  return (
    <OptionCards
      label="SDK"
      options={platforms.map((id) => ({
        ...ANALYTICS_PLATFORM_META[id],
        // REST has no platform logo; a neutral glyph reads better than the JS one.
        icon:
          id === 'rest' ? (
            <Braces className="h-4 w-4" />
          ) : (
            <PlatformIcon
              platform={ANALYTICS_PLATFORM_META[id].iconSlug}
              size="md"
            />
          ),
      }))}
      value={value}
      onChange={onChange}
      disabled={disabled}
    />
  )
}

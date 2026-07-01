import { Globe } from 'lucide-react'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { cn } from '@/lib/utils'

type Option = { value: string; label: string }

type Props = {
  options: Option[]
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}

/**
 * Map a wizard variant (e.g. `flutter-android`, `apple-ios`) to the icon key
 * passed to `PlatformIcon`. We strip the `flutter-` / `react-native-` prefix
 * so the icon renders as a single muted brand SVG instead of triggering
 * `PlatformIcon`'s corner-badge layout (which uses a white pill that looks
 * out of place inside our muted card chrome). The parent card already
 * conveys the Flutter / RN / Apple context.
 */
function variantToIconKey(variant: string): string | null {
  if (variant.startsWith('flutter-')) {
    const os = variant.replace('flutter-', '')
    if (os === 'web') return null
    return os
  }
  if (variant.startsWith('react-native-')) {
    return variant.replace('react-native-', '')
  }
  return variant
}

function TargetIcon({ variant }: { variant: string }) {
  const key = variantToIconKey(variant)
  if (!key) {
    return <Globe className="h-6 w-6" />
  }
  return <PlatformIcon platform={key} size="md" />
}

export function VariantTargetCards({ options, value, onChange, disabled }: Props) {
  const gridClass =
    options.length <= 2
      ? 'grid-cols-2'
      : 'grid-cols-2 sm:grid-cols-3'

  return (
    <div className={cn('grid gap-3', gridClass)}>
      {options.map((o) => {
        const selected = value === o.value
        return (
          <button
            key={o.value}
            type="button"
            disabled={disabled}
            onClick={() => onChange(o.value)}
            className={cn(
              'flex w-full min-w-0 cursor-pointer items-center gap-3 rounded-lg border border-border bg-card/50 p-4 text-start transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              selected
                ? 'border-primary bg-card ring-1 ring-primary/30'
                : 'hover:bg-card',
              disabled && 'pointer-events-none cursor-not-allowed opacity-50',
            )}
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <TargetIcon variant={o.value} />
            </div>
            <span className="min-w-0 text-[13px] font-medium leading-snug text-foreground">
              {o.label}
            </span>
          </button>
        )
      })}
    </div>
  )
}

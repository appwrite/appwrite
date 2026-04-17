import {
  Apple,
  Globe,
  Laptop,
  Smartphone,
  Tv,
  Watch,
} from 'lucide-react'
import { cn } from '@/lib/utils'

type Option = { value: string; label: string }

type Props = {
  options: Option[]
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}

function TargetIcon({ variant }: { variant: string }) {
  const className = 'h-4 w-4 shrink-0 text-muted-foreground'
  switch (variant) {
    case 'flutter-web':
      return <Globe className={className} />
    case 'flutter-linux':
    case 'flutter-macos':
    case 'flutter-windows':
      return <Laptop className={className} />
    case 'apple-ios':
      return <Apple className={className} />
    case 'apple-macos':
      return <Laptop className={className} />
    case 'apple-watchos':
      return <Watch className={className} />
    case 'apple-tvos':
      return <Tv className={className} />
    case 'flutter-android':
    case 'flutter-ios':
    case 'react-native-android':
    case 'react-native-ios':
      return <Smartphone className={className} />
    default:
      return <Smartphone className={className} />
  }
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
              'flex w-full min-w-0 cursor-pointer items-center gap-3 rounded-lg border border-border bg-card/50 p-4 text-left transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              selected
                ? 'border-primary bg-card ring-1 ring-primary/30'
                : 'hover:bg-card',
              disabled && 'pointer-events-none cursor-not-allowed opacity-50',
            )}
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
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

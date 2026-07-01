import { PlatformIcon } from '@/components/global/shared/Icon'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import type { AddAppKind } from '@/lib/add-app-wizard/types'
import { ADD_APP_KINDS } from '@/lib/add-app-wizard/types'
import { cn } from '@/lib/utils'

function iconPlatformForKind(k: AddAppKind): string {
  if (k === 'windows' || k === 'linux') return k
  if (k === 'react-native') return 'react-native'
  if (k === 'apple') return 'apple'
  return k
}

type Props = {
  value: AddAppKind
  onChange: (kind: AddAppKind) => void
  disabled?: boolean
}

export function PlatformKindCards({ value, onChange, disabled }: Props) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      {ADD_APP_KINDS.map((k) => {
        const selected = value === k
        return (
          <button
            key={k}
            type="button"
            disabled={disabled}
            onClick={() => onChange(k)}
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
              <PlatformIcon platform={iconPlatformForKind(k)} size="md" />
            </div>
            <span className="min-w-0 text-[13px] font-medium leading-snug text-foreground">
              {getPlatformDisplayName(k)}
            </span>
          </button>
        )
      })}
    </div>
  )
}

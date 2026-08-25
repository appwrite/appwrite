import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import type { WebFrameworkKey } from '@/lib/add-app-wizard/types'
import { WEB_FRAMEWORK_META } from '@/lib/add-app-wizard/platform-map'
import { cn } from '@/lib/utils'

const ORDER: WebFrameworkKey[] = [
  'react',
  'tanstack-start',
  'nextjs',
  'vue',
  'nuxt',
  'svelte',
  'sveltekit',
  'angular',
  'analog',
  'remix',
  'solid',
  'vite',
  'astro',
  'js',
]

type Props = {
  value: WebFrameworkKey
  onChange: (framework: WebFrameworkKey) => void
  disabled?: boolean
}

export function WebFrameworkCards({ value, onChange, disabled }: Props) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      {ORDER.map((k) => {
        const selected = value === k
        const label = WEB_FRAMEWORK_META[k].label
        const iconKey = k === 'js' ? 'vanilla' : k
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
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
              <FrameworkIcon framework={iconKey} size="md" />
            </div>
            <span className="min-w-0 text-[13px] font-medium leading-snug text-foreground">
              {label}
            </span>
          </button>
        )
      })}
    </div>
  )
}

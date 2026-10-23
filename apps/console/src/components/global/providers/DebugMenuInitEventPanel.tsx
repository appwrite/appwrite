import { useEffect, useState } from 'react'
import {
  formatMockInitActiveEventLabel,
  listInitActiveEventDebugOptions,
} from '@/lib/init/active-event-debug'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import { cn } from '@/lib/utils'

const EVENT_OPTIONS = listInitActiveEventDebugOptions()

export function DebugMenuInitEventPanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const selectedId = overrides.mockInitActiveEventId

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init active event">
      <div className="rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">Active event</p>
        <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
          {formatMockInitActiveEventLabel(selectedId)}
        </p>
        <p className="mt-2 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Overrides which Init event `/init`, tickets, and org promo use in this
          browser. Production default keeps Init 4 featured until Init 5 ships.
        </p>
      </div>

      <ul className="space-y-1 px-2 pb-2">
        {EVENT_OPTIONS.map((option) => {
          const isSelected =
            option.id === selectedId ||
            (option.id === null && selectedId === null)
          return (
            <li key={option.id ?? 'production-default'}>
              <button
                type="button"
                className={cn(
                  'w-full rounded-md px-2 py-2 text-left text-[12px] transition-colors',
                  isSelected
                    ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,transparent)] font-medium text-foreground'
                    : 'text-[var(--network-globe-edge)]/90 hover:bg-muted/40',
                )}
                onClick={() =>
                  setDebugOverride('mockInitActiveEventId', option.id)
                }
              >
                {option.label}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

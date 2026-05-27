import { useEffect, useState } from 'react'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import {
  formatInitMockTicketType,
  type InitTicketTypeId,
} from '@/lib/init/ticket-types'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

const TICKET_TYPE_OPTIONS: {
  id: InitTicketTypeId
  label: string
  description: string
}[] = [
  {
    id: 'standard',
    label: 'Standard',
    description: 'Theme-based light or dark pass',
  },
  {
    id: 'silver',
    label: 'Silver VIP',
    description: '3+ year members · Appwrite VIP',
  },
  {
    id: 'gold',
    label: 'Gold contributor',
    description: 'Verified @appwrite.io · Contributor pass',
  },
]

export function DebugMenuInitTicketPanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const mockEnabled = overrides.mockInitTicketType !== null
  const selectedType = overrides.mockInitTicketType ?? 'standard'

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init ticket mock">
      <div className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-[#E5DEFF]">Mock ticket type</p>
          <p className="mt-0.5 text-[11px] text-[#9B87F5]/80">
            {mockEnabled
              ? formatInitMockTicketType(selectedType)
              : 'Use account rules on /init'}
          </p>
        </div>
        <Switch
          checked={mockEnabled}
          onCheckedChange={(checked) => {
            setDebugOverride(
              'mockInitTicketType',
              checked ? selectedType : null,
            )
          }}
          className="shrink-0"
        />
      </div>

      {mockEnabled ? (
        <div className="space-y-2 rounded-lg px-3 pb-3">
          {TICKET_TYPE_OPTIONS.map((option) => {
            const active = selectedType === option.id
            return (
              <button
                key={option.id}
                type="button"
                onClick={() => setDebugOverride('mockInitTicketType', option.id)}
                className={cn(
                  'flex w-full flex-col rounded-lg border px-3 py-2.5 text-left transition-colors',
                  active
                    ? 'border-[#9B87F5]/60 bg-[#9B87F5]/15'
                    : 'border-[#9B87F5]/20 hover:bg-[#9B87F5]/10',
                )}
              >
                <span className="text-[13px] font-medium text-[#E5DEFF]">
                  {option.label}
                </span>
                <span className="mt-0.5 text-[11px] text-[#9B87F5]/80">
                  {option.description}
                </span>
              </button>
            )
          })}
          <p className="pt-1 text-[11px] leading-relaxed text-[#9B87F5]/80">
            Overrides gold, silver, and standard rules while enabled. Sign in on
            /init to preview the ticket.
          </p>
        </div>
      ) : null}
    </div>
  )
}

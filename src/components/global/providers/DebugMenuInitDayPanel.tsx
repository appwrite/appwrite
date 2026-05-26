import { useEffect, useState } from 'react'
import { Switch } from '@/components/ui/switch'
import { Slider } from '@/components/ui/slider'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

export function DebugMenuInitDayPanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const mockEnabled = overrides.mockInitCurrentDay !== null
  const selectedDay = overrides.mockInitCurrentDay ?? 1

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init current day">
      <div className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-[#E5DEFF]">Mock current day</p>
          <p className="mt-0.5 text-[11px] text-[#9B87F5]/80">
            {mockEnabled
              ? `Preview Init as day ${selectedDay} of 5`
              : 'Use the real calendar date on /init'}
          </p>
        </div>
        <Switch
          checked={mockEnabled}
          onCheckedChange={(checked) => {
            setDebugOverride('mockInitCurrentDay', checked ? selectedDay : null)
          }}
          className="shrink-0"
        />
      </div>

      {mockEnabled ? (
        <div className="space-y-3 rounded-lg px-3 pb-3">
          <div className="flex items-center justify-between text-[11px] text-[#9B87F5]/80">
            <span>Day 1</span>
            <span className="font-medium text-[#E5DEFF]">Day {selectedDay}</span>
            <span>Day 5</span>
          </div>
          <Slider
            min={1}
            max={5}
            step={1}
            value={[selectedDay]}
            onValueChange={([value]) => {
              if (value) setDebugOverride('mockInitCurrentDay', value)
            }}
            aria-label="Mock Init current day"
          />
          <p className="text-[11px] leading-relaxed text-[#9B87F5]/80">
            Days after day {selectedDay} stay locked; their titles, resources, and
            schedule entries are not rendered.
          </p>
        </div>
      ) : null}
    </div>
  )
}

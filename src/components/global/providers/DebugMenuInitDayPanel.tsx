import { useEffect, useState } from 'react'
import { Slider } from '@/components/ui/slider'
import {
  formatInitMockCurrentDay,
  getInitMockCurrentDayMax,
  getInitMockDayAfter,
  getInitMockDayBannerExpired,
  INIT_LAUNCH_WEEK_DAY_COUNT,
  INIT_MOCK_DAY_BEFORE,
} from '@/lib/init/mock-current-day'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

const MOCK_DAY_MIN = INIT_MOCK_DAY_BEFORE
const MOCK_DAY_AFTER = getInitMockDayAfter()
const MOCK_DAY_BANNER_EXPIRED = getInitMockDayBannerExpired()
const MOCK_DAY_MAX = getInitMockCurrentDayMax()

function mockDaySliderLabel(day: number): string {
  if (day === INIT_MOCK_DAY_BEFORE) return 'Before'
  if (day === MOCK_DAY_AFTER) return 'After'
  if (day === MOCK_DAY_BANNER_EXPIRED) return 'Banner off'
  return `Day ${day}`
}

function mockDayPreviewCopy(day: number): string {
  if (day === INIT_MOCK_DAY_BEFORE) {
    return 'Before the event - all days stay locked. This is the default until you advance the slider.'
  }
  if (day === MOCK_DAY_AFTER) {
    return 'Simulates after the event. Recap mode with all days unlocked.'
  }
  if (day === MOCK_DAY_BANNER_EXPIRED) {
    return 'Simulates 7+ days after the event. Org promo banner is hidden.'
  }
  return `Simulates day ${day} - unlocks days 1-${day} (schedule, detail cards, Discord sessions, live badges).`
}

export function DebugMenuInitDayPanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const selectedDay = overrides.mockInitCurrentDay

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init current day">
      <div className="rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">Current day</p>
        <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
          {formatInitMockCurrentDay(selectedDay)}
        </p>
      </div>

      <div className="space-y-3 rounded-lg px-3 pb-3">
        <div className="flex items-center justify-between text-[11px] text-[var(--network-globe-edge)]/80">
          <span>Before</span>
          <span className="font-medium text-foreground">
            {mockDaySliderLabel(selectedDay)}
          </span>
          <span>Banner off</span>
        </div>
        <Slider
          min={MOCK_DAY_MIN}
          max={MOCK_DAY_MAX}
          step={1}
          value={[selectedDay]}
          onValueChange={([value]) => {
            if (value !== undefined) setDebugOverride('mockInitCurrentDay', value)
          }}
          aria-label="Init current day"
        />
        <div className="flex justify-between text-[10px] text-[var(--network-globe-edge)]/60">
          <span>Day 1</span>
          <span>Day {INIT_LAUNCH_WEEK_DAY_COUNT}</span>
        </div>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          {mockDayPreviewCopy(selectedDay)}
        </p>
      </div>
    </div>
  )
}

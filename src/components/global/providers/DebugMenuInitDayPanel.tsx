import { useEffect, useState } from 'react'
import { DebugMenuSwitch } from '@/components/global/providers/DebugMenuSwitch'
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
    return 'Simulates before the event - all days stay locked.'
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
  const mockEnabled = overrides.mockInitCurrentDay !== null
  const selectedDay = overrides.mockInitCurrentDay ?? 1

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init current day">
      <div className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-foreground">Mock current day</p>
          <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
            {mockEnabled
              ? formatInitMockCurrentDay(selectedDay)
              : 'Use the real calendar date on /init'}
          </p>
        </div>
        <DebugMenuSwitch
          checked={mockEnabled}
          onCheckedChange={(checked) => {
            setDebugOverride('mockInitCurrentDay', checked ? selectedDay : null)
          }}
          className="shrink-0"
        />
      </div>

      {mockEnabled ? (
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
            aria-label="Mock Init current day"
          />
          <div className="flex justify-between text-[10px] text-[var(--network-globe-edge)]/60">
            <span>Day 1</span>
            <span>Day {INIT_LAUNCH_WEEK_DAY_COUNT}</span>
          </div>
          <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
            {mockDayPreviewCopy(selectedDay)}
          </p>
        </div>
      ) : null}
    </div>
  )
}

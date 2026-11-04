import { TimezoneSelect } from '@/components/global/shared/TimezoneSelect'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  FIREWALL_SCHEDULE_INTERVAL_MAX,
  FIREWALL_SCHEDULE_INTERVAL_MIN,
  FIREWALL_SCHEDULE_MODES,
  FIREWALL_SCHEDULE_WEEKDAYS,
  scheduleUsesAnchor,
  scheduleUsesDays,
  scheduleUsesInterval,
  type FirewallScheduleDraft,
  type FirewallScheduleMode,
} from '@/lib/firewall/schedule'
import { useT } from '@/lib/i18n/translate'

type RuleScheduleProps = {
  value: FirewallScheduleDraft
  onChange: (next: FirewallScheduleDraft) => void
  disabled?: boolean
}

/** When a firewall rule is active. Off means always active. */
export function RuleSchedule({ value, onChange, disabled }: RuleScheduleProps) {
  const t = useT()
  const set = (patch: Partial<FirewallScheduleDraft>) =>
    onChange({ ...value, ...patch })

  const crossesMidnight =
    !value.allDay &&
    value.startTime !== '' &&
    value.endTime !== '' &&
    value.startTime > value.endTime

  return (
    <div className="rounded-lg border border-border">
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        <div>
          <p className="text-[13px] font-medium text-foreground">
            {t('Schedule')}
          </p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {t(
              'Apply this rule only on certain days or at certain times. When off, the rule is always active.',
            )}
          </p>
        </div>
        <Switch
          checked={value.enabled}
          onCheckedChange={(enabled) => set({ enabled })}
          disabled={disabled}
          aria-label={t('Schedule')}
        />
      </div>

      {value.enabled ? (
        <div className="space-y-4 border-t border-border px-4 py-4">
          <div className="space-y-1.5">
            <Label htmlFor="firewall-schedule-mode" className="text-[12px]">
              {t('Repeat')}
            </Label>
            <Select
              value={value.mode}
              onValueChange={(mode) =>
                set({ mode: mode as FirewallScheduleMode })
              }
              disabled={disabled}
            >
              <SelectTrigger id="firewall-schedule-mode" className="h-9 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {FIREWALL_SCHEDULE_MODES.map((mode) => (
                  <SelectItem key={mode.value} value={mode.value}>
                    {t(mode.label)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {scheduleUsesDays(value.mode) ? (
            <div className="space-y-1.5">
              <Label className="text-[12px]">{t('Days of the week')}</Label>
              <ToggleGroup
                type="multiple"
                variant="outline"
                size="sm"
                value={value.days.map(String)}
                onValueChange={(days) => set({ days: days.map(Number) })}
                disabled={disabled}
                className="flex-wrap justify-start"
              >
                {FIREWALL_SCHEDULE_WEEKDAYS.map((day) => (
                  <ToggleGroupItem
                    key={day.value}
                    value={String(day.value)}
                    className="min-w-11"
                  >
                    {t(day.label)}
                  </ToggleGroupItem>
                ))}
              </ToggleGroup>
              {value.days.length === 0 ? (
                <p className="text-[12px] text-destructive">
                  {t('Select at least one day.')}
                </p>
              ) : null}
            </div>
          ) : null}

          {scheduleUsesInterval(value.mode) ||
          scheduleUsesAnchor(value.mode) ? (
            <div className="grid gap-3 sm:grid-cols-2">
              {scheduleUsesInterval(value.mode) ? (
                <div className="space-y-1.5">
                  <Label
                    htmlFor="firewall-schedule-interval"
                    className="text-[12px]"
                  >
                    {t('Repeat every (days)')}
                  </Label>
                  <NumberInput
                    id="firewall-schedule-interval"
                    min={FIREWALL_SCHEDULE_INTERVAL_MIN}
                    max={FIREWALL_SCHEDULE_INTERVAL_MAX}
                    value={value.interval}
                    onValueChange={(interval) => set({ interval })}
                    disabled={disabled}
                  />
                </div>
              ) : null}
              <div className="space-y-1.5">
                <Label
                  htmlFor="firewall-schedule-anchor"
                  className="text-[12px]"
                >
                  {t('Starting on')}
                </Label>
                <Input
                  id="firewall-schedule-anchor"
                  type="date"
                  value={value.anchor}
                  onChange={(e) => set({ anchor: e.target.value })}
                  disabled={disabled}
                />
                <p className="text-[12px] text-muted-foreground">
                  {value.mode === 'biweekly'
                    ? t('The week of this date is the first active week.')
                    : t(
                        'The first active day. The rule is never active before it.',
                      )}
                </p>
              </div>
            </div>
          ) : null}

          <div className="space-y-3">
            <div className="flex items-center justify-between gap-4">
              <Label
                htmlFor="firewall-schedule-all-day"
                className="text-[12px]"
              >
                {t('All day')}
              </Label>
              <Switch
                id="firewall-schedule-all-day"
                checked={value.allDay}
                onCheckedChange={(allDay) => set({ allDay })}
                disabled={disabled}
              />
            </div>
            {!value.allDay ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label
                    htmlFor="firewall-schedule-start"
                    className="text-[12px]"
                  >
                    {t('Start time')}
                  </Label>
                  <Input
                    id="firewall-schedule-start"
                    type="time"
                    value={value.startTime}
                    onChange={(e) => set({ startTime: e.target.value })}
                    disabled={disabled}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label
                    htmlFor="firewall-schedule-end"
                    className="text-[12px]"
                  >
                    {t('End time')}
                  </Label>
                  <Input
                    id="firewall-schedule-end"
                    type="time"
                    value={value.endTime}
                    onChange={(e) => set({ endTime: e.target.value })}
                    disabled={disabled}
                  />
                </div>
                {value.startTime !== '' && value.startTime === value.endTime ? (
                  <p className="text-[12px] text-destructive sm:col-span-2">
                    {t(
                      'Start and end times must differ. Turn on All day for the whole day.',
                    )}
                  </p>
                ) : crossesMidnight ? (
                  <p className="text-[12px] text-muted-foreground sm:col-span-2">
                    {t(
                      'This window crosses midnight: it starts on each selected day and ends the next morning.',
                    )}
                  </p>
                ) : null}
              </div>
            ) : null}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="firewall-schedule-timezone" className="text-[12px]">
              {t('Time zone')}
            </Label>
            <TimezoneSelect
              id="firewall-schedule-timezone"
              value={value.timezone}
              onValueChange={(timezone) => set({ timezone })}
              disabled={disabled}
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                "Days and times follow this time zone's clock, including daylight saving.",
              )}
            </p>
          </div>
        </div>
      ) : null}
    </div>
  )
}

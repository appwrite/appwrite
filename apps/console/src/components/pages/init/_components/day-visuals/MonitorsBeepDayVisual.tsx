import { BellRing, ShieldAlert, Sparkles, type LucideIcon } from 'lucide-react'
import { CoverCard, InitDayCover } from './InitDayCover'

/** Project updates arriving in Beep: one monitor alert among them. */
const PUSHES: {
  icon: LucideIcon
  title: string
  detail: string
  alert?: boolean
}[] = [
  {
    icon: BellRing,
    title: 'Monitor triggered',
    detail: 'Bandwidth at 84%',
    alert: true,
  },
  {
    icon: ShieldAlert,
    title: 'SSL certificate failed',
    detail: 'api.example.com',
  },
  { icon: Sparkles, title: 'New in Appwrite', detail: 'Init 5, day 4' },
]

/** Init 5 day 4: a monitor fires and Beep puts it on your phone among other project updates. */
export function MonitorsBeepDayVisual() {
  return (
    <InitDayCover
      headline="Alerts, on your phone"
      mockClassName="start-[57cqw] top-[9cqw] w-[44cqw]"
    >
      {/* Beep: the phone, running off the bottom like the covers' phones. */}
      <CoverCard className="relative w-full rounded-[2.6em] p-[0.7em] pb-[6em]">
        <span
          className="mx-auto mb-[0.9em] block h-[1.2em] w-[6em] rounded-full bg-black/70"
          aria-hidden
        />
        <p className="mb-[0.6em] px-[0.3em] font-semibold text-white">Beep</p>
        <ul className="space-y-[0.5em]">
          {PUSHES.map((push) => {
            const PushIcon = push.icon
            return (
              <li
                key={push.title}
                className={
                  push.alert
                    ? 'rounded-[0.9em] border border-[var(--brand-cta)] bg-[rgb(253_54_110/0.12)] px-[0.7em] py-[0.5em]'
                    : 'rounded-[0.9em] border border-white/8 bg-white/5 px-[0.7em] py-[0.5em]'
                }
              >
                <p className="flex items-center gap-[0.4em] font-medium text-white">
                  <PushIcon
                    className={
                      push.alert
                        ? 'size-[1em] shrink-0 text-[var(--brand-cta)]'
                        : 'size-[1em] shrink-0 text-white/60'
                    }
                    aria-hidden
                  />
                  <span className="truncate">{push.title}</span>
                </p>
                <p className="truncate ps-[1.4em] text-[0.85em] text-white/55">
                  {push.detail}
                </p>
              </li>
            )
          })}
        </ul>
      </CoverCard>
    </InitDayCover>
  )
}

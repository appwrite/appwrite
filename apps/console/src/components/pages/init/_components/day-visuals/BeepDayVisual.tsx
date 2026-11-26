import { BellRing } from 'lucide-react'
import { cn } from '@/lib/utils'
import { InitDayCover } from './InitDayCover'

/** Updates from your projects on a lock screen, newest first. */
const PUSHES = [
  {
    title: 'New in Appwrite',
    detail: 'Appwrite Zero is live',
    time: 'now',
  },
  {
    title: 'SSL certificate failed',
    detail: 'api.my-app.com · my-app',
    time: '8m',
  },
  {
    title: 'Monitor triggered',
    detail: 'Bandwidth at 84% · my-app',
    time: '1h',
  },
]

/**
 * Init 5 day 4, Beep: a phone's lock screen with updates from your projects
 * arriving as pushes. Its own picture, apart from the Monitors cover: Beep
 * brings every kind of project update, a monitor firing is only one of them.
 */
export function BeepDayVisual() {
  return (
    <InitDayCover
      headline="Your projects, in your pocket"
      mockClassName="start-[55cqw] top-[7cqw] w-[36cqw]"
    >
      {/* The phone: one outline, running off the bottom edge. */}
      <div className="rounded-[2.4em] border border-white/15 bg-[#121214] p-[0.55em] shadow-[0_2cqw_6cqw_rgb(0_0_0/0.45)]">
        <div className="relative overflow-hidden rounded-[1.95em] bg-[radial-gradient(120%_70%_at_50%_0%,rgb(124_103_254/0.35),transparent_60%),linear-gradient(#1d1b26,#141317)] px-[0.7em] pb-[8em] pt-[0.9em]">
          <div className="mx-auto h-[1.1em] w-[4.6em] rounded-full bg-black" />
          <p className="mt-[1.2em] text-center text-[0.85em] text-white/60">
            Thursday, December 3
          </p>
          <p className="text-center font-aeonik-pro text-[3.4em] leading-none tracking-tight text-white">
            9:41
          </p>
          <div className="mt-[1.4em] space-y-[0.5em]">
            {PUSHES.map((push, index) => (
              <div
                key={push.title}
                className={cn(
                  'rounded-[1em] bg-white/10 px-[0.75em] py-[0.6em] backdrop-blur',
                  index === 1 && 'opacity-85',
                  index === 2 && 'opacity-65',
                )}
              >
                <p className="flex items-center gap-[0.4em] text-[0.72em] uppercase tracking-wider text-white/55">
                  <span className="flex size-[1.45em] items-center justify-center rounded-[0.4em] bg-[var(--brand-cta)]">
                    <BellRing className="size-[0.9em] text-white" aria-hidden />
                  </span>
                  Beep
                  <span className="ms-auto normal-case tracking-normal">
                    {push.time}
                  </span>
                </p>
                <p className="mt-[0.3em] text-[0.95em] font-medium text-white">
                  {push.title}
                </p>
                <p className="text-[0.82em] text-white/60">{push.detail}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </InitDayCover>
  )
}

import { BellRing } from 'lucide-react'
import { cn } from '@/lib/utils'
import { InitDayCover } from './InitDayCover'

/** Updates from your projects, as Beep delivers them: the newest on top. */
const PUSHES = [
  {
    title: 'Monitor triggered',
    detail: 'Bandwidth at 84% · my-app',
    time: 'now',
  },
  {
    title: 'SSL certificate failed',
    detail: 'api.my-app.com · my-app',
    time: '12m',
  },
  { title: 'New in Appwrite', detail: 'Appwrite Zero is live', time: '1h' },
]

/** Init 5 day 4, Beep: your projects' updates arriving on your phone as pushes. */
export function BeepDayVisual() {
  return (
    <InitDayCover
      headline="Your projects, in your pocket"
      mockClassName="start-[50cqw] top-[12cqw] w-[46cqw]"
    >
      <div className="space-y-[0.7em]">
        {PUSHES.map((push, index) => (
          <div
            key={push.title}
            className={cn(
              'rounded-[1.1em] border border-white/12 bg-[#2c2c30]/95 px-[0.9em] py-[0.7em] shadow-[0_2cqw_5cqw_rgb(0_0_0/0.45)]',
              index > 0 && 'opacity-80',
              index > 1 && 'opacity-60',
            )}
          >
            <p className="flex items-center gap-[0.45em] text-[0.75em] uppercase tracking-wider text-white/50">
              <span className="flex size-[1.4em] items-center justify-center rounded-[0.35em] bg-[var(--brand-cta)]">
                <BellRing className="size-[0.85em] text-white" aria-hidden />
              </span>
              Beep
              <span className="ms-auto normal-case tracking-normal">
                {push.time}
              </span>
            </p>
            <p className="mt-[0.35em] font-medium text-white">{push.title}</p>
            <p className="text-[0.85em] text-white/60">{push.detail}</p>
          </div>
        ))}
      </div>
    </InitDayCover>
  )
}

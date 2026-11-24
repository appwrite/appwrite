import { Bot, Check } from 'lucide-react'
import { CoverCard, CoverLine, InitDayCover } from './InitDayCover'

/** What an agent does with a Zero project before anyone signs up. */
const STEPS = [
  'Project ready, no account',
  'Created table tasks',
  'Enabled email sign-in',
] as const

/** Init 5 day 3: an agent builds on a fresh project; claim it later. */
export function ZeroDayVisual() {
  return (
    <InitDayCover
      headline="Build first, sign up later"
      mockClassName="start-[47cqw] top-[12cqw] w-[49cqw]"
    >
      <div className="space-y-[0.8em]">
        <CoverCard className="flex items-center gap-[0.7em] px-[0.9em] py-[0.8em]">
          <span className="flex size-[2em] shrink-0 items-center justify-center rounded-full bg-white/10">
            <Bot className="size-[1.1em] text-white/80" aria-hidden />
          </span>
          <span className="text-white">Build a task tracker.</span>
        </CoverCard>
        <CoverCard className="space-y-[0.6em] px-[0.9em] py-[0.9em]">
          {STEPS.map((step) => (
            <p
              key={step}
              className="flex items-center gap-[0.6em] text-white/85"
            >
              <Check
                className="size-[1.1em] shrink-0 text-emerald-400"
                aria-hidden
              />
              <span className="truncate">{step}</span>
            </p>
          ))}
          <CoverLine className="ms-[1.7em] w-[60%]" />
        </CoverCard>
        <div className="flex items-center gap-[0.8em]">
          <span className="rounded-[0.5em] bg-[var(--brand-cta)] px-[1em] py-[0.55em] font-medium text-white">
            Claim project
          </span>
          <CoverLine className="w-[30%]" />
        </div>
      </div>
    </InitDayCover>
  )
}

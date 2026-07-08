import type { CSSProperties } from 'react'
import type { Organization } from '@/lib/utils/mock-data'
import { cn } from '@/lib/utils'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import { useT } from '@/lib/i18n/translate'

const SHARD_SPECS = [
  {
    clip: 'polygon(50% 0%, 0% 90%, 100% 65%)',
    sx: -17,
    sy: -13,
    r0: -18,
    r1: 6,
    r2: -32,
    d: 0,
  },
  {
    clip: 'polygon(15% 0%, 100% 0%, 85% 100%)',
    sx: 18,
    sy: -11,
    r0: 14,
    r1: 28,
    r2: 48,
    d: 0.08,
  },
  {
    clip: 'polygon(0 25%, 45% 100%, 100% 70%)',
    sx: -12,
    sy: 16,
    r0: -8,
    r1: -22,
    r2: -38,
    d: 0.04,
  },
  {
    clip: 'polygon(0 0, 100% 35%, 55% 100%)',
    sx: 14,
    sy: 14,
    r0: 22,
    r1: 10,
    r2: 36,
    d: 0.14,
  },
  {
    clip: 'polygon(50% 0%, 0% 55%, 100% 100%)',
    sx: -19,
    sy: 5,
    r0: -28,
    r1: -12,
    r2: -42,
    d: 0.18,
  },
  {
    clip: 'polygon(0 45%, 100% 0%, 100% 100%)',
    sx: 6,
    sy: -18,
    r0: 8,
    r1: 18,
    r2: 32,
    d: 0.1,
  },
  {
    clip: 'polygon(30% 0%, 100% 40%, 0% 100%)',
    sx: 3,
    sy: 17,
    r0: 35,
    r1: 52,
    r2: 68,
    d: 0.06,
  },
] as const

type ProjectSelectorPlanBadgeProps = {
  plan: Organization['plan']
  /** When true, play shatter / stress motion (same signal as billing header alert - no extra fetch). */
  billingStress: boolean
  upcomingDowngrade?: boolean
  className?: string
}

export function ProjectSelectorPlanBadge({
  plan,
  billingStress,
  upcomingDowngrade = false,
  className,
}: ProjectSelectorPlanBadgeProps) {
  const t = useT()
  const label = upcomingDowngrade ? t('Downgraded') : getPlanDisplayName(plan)
  const colors = upcomingDowngrade
    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
    : getPlanBadgeColor(plan)

  if (!billingStress || upcomingDowngrade) {
    return (
      <span
        className={cn(
          'shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium capitalize',
          colors,
          className,
        )}
      >
        {label}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'plan-badge-stress-outer inline-flex shrink-0 overflow-visible',
        className,
      )}
    >
      <span
        className={cn(
          'plan-badge-stress-inner relative isolate inline-flex shrink-0 items-center justify-center overflow-visible rounded-md px-1.5 py-0.5 text-[10px] font-medium capitalize',
          colors,
        )}
      >
        <span
          className="pointer-events-none absolute inset-0 z-[6] flex items-center justify-center overflow-visible"
          aria-hidden
        >
          {SHARD_SPECS.map((s, i) => (
            <span
              key={i}
              className="plan-badge-shard"
              style={
                {
                  clipPath: s.clip,
                  '--sx': `${s.sx}px`,
                  '--sy': `${s.sy}px`,
                  '--r0': `${s.r0}deg`,
                  '--r1': `${s.r1}deg`,
                  '--r2': `${s.r2}deg`,
                  '--shard-delay': `${s.d}s`,
                } as CSSProperties
              }
            />
          ))}
        </span>
        <span
          className="plan-badge-stress-crack pointer-events-none absolute start-1/2 top-[12%] z-[5] w-px bg-gradient-to-b from-transparent via-foreground/45 to-transparent dark:via-foreground/40"
          style={{ bottom: '12%' }}
          aria-hidden
        />
        <span className="plan-badge-stress-label relative z-[4] leading-none">
          {label}
        </span>
      </span>
    </span>
  )
}

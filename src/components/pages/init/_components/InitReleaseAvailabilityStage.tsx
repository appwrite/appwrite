import type { ReactNode } from 'react'
import type {
  InitDisplayEvent,
  LaunchEventReleaseOption,
} from '@/lib/init/types'
import { INIT_RELEASE_AVAILABILITY_SECTION_ID } from '@/lib/init/init-section-ids'
import { isExternalInitHref } from '@/lib/init/links'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { parseDocsPagePath } from '@/lib/marketing/urls'
import { InitDayCountdown } from './InitDayCountdown'
import { ChevronRight, Cloud, Lock, Server } from 'lucide-react'
import { cn } from '@/lib/utils'

function isOptionUnlocked(
  event: InitDisplayEvent,
  optionUnlockDay: number,
): boolean {
  return event.isRecapMode || event.currentDay >= optionUnlockDay
}

function ReleaseOptionLink({
  option,
  children,
  className,
  locked = false,
}: {
  option: LaunchEventReleaseOption
  children: ReactNode
  className?: string
  locked?: boolean
}) {
  if (locked) {
    return (
      <div className={className} aria-disabled>
        {children}
      </div>
    )
  }

  const isDocsLink = Boolean(parseDocsPagePath(option.href))
  const external = option.external ?? isExternalInitHref(option.href)

  if (isDocsLink) {
    return (
      <DocsRouteLink href={option.href} className={className}>
        {children}
      </DocsRouteLink>
    )
  }

  return (
    <a
      href={option.href}
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      className={className}
    >
      {children}
    </a>
  )
}

interface InitReleaseAvailabilityStageProps {
  event: InitDisplayEvent
}

/**
 * Hybrid of the deployment picker (segmented track + row CTAs)
 * and the fork path (centered headline with a visual split into two paths).
 * Cloud unlocks day-by-day; Community Edition waits for the full release.
 */
export function InitReleaseAvailabilityStage({
  event,
}: InitReleaseAvailabilityStageProps) {
  const release = event.releaseAvailability
  if (!release) return null

  const cloudUnlockDay = release.cloud.unlockDay ?? 1
  const selfHostUnlockDay = release.selfHosted.unlockDay ?? release.unlockDay
  const cloudUnlocked = isOptionUnlocked(event, cloudUnlockDay)
  const selfHostedUnlocked = isOptionUnlocked(event, selfHostUnlockDay)
  const fullReleaseUnlocked = selfHostedUnlocked
  const options = [
    {
      option: release.cloud,
      icon: Cloud,
      label: 'Cloud',
      unlocked: cloudUnlocked,
      unlockDay: cloudUnlockDay,
    },
    {
      option: release.selfHosted,
      icon: Server,
      label: 'Self-host',
      unlocked: selfHostedUnlocked,
      unlockDay: selfHostUnlockDay,
    },
  ] as const

  return (
    <>
      <div className="border-t border-border" aria-hidden />
      <div className="py-8">
        <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
          <section
            id={INIT_RELEASE_AVAILABILITY_SECTION_ID}
            className="scroll-mt-28 flex flex-col items-center"
          >
            <div className="max-w-2xl text-center">
              <h3 className="text-[15px] font-semibold text-foreground">
                {fullReleaseUnlocked ? release.sectionTitle : release.lockedTitle}
              </h3>
              {fullReleaseUnlocked ? (
                release.sectionDescription ? (
                  <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
                    {release.sectionDescription}
                  </p>
                ) : null
              ) : (
                <>
                  <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
                    {release.lockedDescription}
                  </p>
                  <InitDayCountdown
                    eventStartDate={event.startDate}
                    dayNumber={selfHostUnlockDay}
                    className="mt-3"
                  />
                </>
              )}
            </div>

            {/* Fork stem */}
            <div className="mt-6 flex w-full max-w-4xl flex-col items-center" aria-hidden>
              <div className="h-6 w-px bg-border" />
              <div className="relative flex w-full justify-center">
                <div className="h-px w-full max-w-xl bg-gradient-to-r from-transparent via-border to-transparent" />
              </div>
              <div className="grid w-full max-w-xl grid-cols-2">
                <div className="h-5 border-e border-border" />
                <div className="h-5 flex-1 border-s border-border" />
              </div>
            </div>

            <div className="w-full max-w-4xl rounded-2xl border border-border bg-muted/40 p-1.5">
              <div className="grid gap-1.5 sm:grid-cols-2">
                {options.map(({ option, icon: Icon, label, unlocked, unlockDay }) => (
                  <ReleaseOptionLink
                    key={option.title}
                    option={option}
                    locked={!unlocked}
                    className={cn(
                      'group relative flex items-start gap-3 rounded-xl px-5 py-5 pt-5 text-start transition-all',
                      unlocked
                        ? 'bg-background shadow-sm hover:bg-accent/50'
                        : 'border border-dashed border-border/80 bg-background/40 opacity-90',
                    )}
                  >
                    <span
                      className={cn(
                        'absolute -top-2.5 left-1/2 -translate-x-1/2 rounded-full border px-2 py-px text-[10px] font-semibold uppercase tracking-[0.2em]',
                        unlocked
                          ? 'border-border bg-background text-muted-foreground'
                          : 'border-dashed border-border bg-muted/50 text-muted-foreground/70',
                      )}
                    >
                      {label}
                    </span>
                    <div
                      className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-lg',
                        unlocked
                          ? 'bg-[color-mix(in_srgb,var(--brand-cta)_12%,transparent)] text-[var(--brand-cta)]'
                          : 'bg-muted text-muted-foreground/60',
                      )}
                    >
                      <Icon className="size-5" aria-hidden />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p
                        className={cn(
                          'text-[13px] font-medium',
                          unlocked ? 'text-foreground' : 'text-muted-foreground',
                        )}
                      >
                        {option.title}
                      </p>
                      {option.availabilityLabel ? (
                        <p
                          className={cn(
                            'mt-1 text-[10px] font-semibold uppercase tracking-[0.2em]',
                            unlocked
                              ? 'text-[var(--brand-cta)]'
                              : 'text-muted-foreground/70',
                          )}
                        >
                          {option.availabilityLabel}
                        </p>
                      ) : null}
                      <p className="mt-2 line-clamp-2 text-[13px] leading-snug text-muted-foreground">
                        {option.description}
                      </p>
                      <p className="mt-2 text-[13px] font-medium text-muted-foreground transition-colors group-hover:text-foreground">
                        {unlocked
                          ? option.ctaLabel
                          : unlockDay > 1
                            ? `Unlocks on day ${unlockDay}`
                            : 'Coming soon'}
                      </p>
                    </div>
                    {unlocked ? (
                      <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-[var(--brand-cta)]"
                        aria-hidden
                      />
                    ) : (
                      <Lock
                        className="size-4 shrink-0 text-muted-foreground/60"
                        aria-hidden
                      />
                    )}
                  </ReleaseOptionLink>
                ))}
              </div>
            </div>
          </section>
        </div>
      </div>
    </>
  )
}

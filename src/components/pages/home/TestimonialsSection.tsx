import { useEffect, useState, type KeyboardEvent } from 'react'
import { ArrowRight } from 'lucide-react'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { homeCaseStudies, type HomeCaseStudy } from '@/lib/home/case-studies'
import { cn } from '@/lib/utils'
import { HomeSoftLights } from './HomeSoftLights'

const PANEL_RESIZE_MS = 300

function CaseStudyDottedSeparator() {
  return (
    <div
      aria-hidden
      className="h-px w-full bg-[repeating-linear-gradient(90deg,var(--border)_0,var(--border)_2px,transparent_2px,transparent_7px)]"
    />
  )
}

function CaseStudyPanelContent({ study }: { study: HomeCaseStudy }) {
  const initials = study.name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

  const tabId = `case-study-tab-${study.id}`
  const panelId = `case-study-panel-${study.id}`

  return (
    <div
      id={panelId}
      role="tabpanel"
      aria-labelledby={tabId}
      className="flex w-[min(100%,36rem)] min-w-[17.5rem] max-w-none flex-col gap-5 p-6 text-left lg:w-[min(100%,40rem)] lg:min-w-[28rem] lg:p-10"
    >
      <div className="flex h-6 w-full max-w-[min(100%,150px)] items-center sm:h-7 md:h-8">
        <TrustedByLogo
          src={study.logo}
          alt={study.company}
          width={study.logoWidth}
          height={study.logoHeight}
          mask={study.logoMask}
          emphasized
          className="max-h-full w-auto object-left group-hover:scale-100"
        />
      </div>

      <h3 className="font-aeonik-pro max-w-[20ch] text-pretty text-[22px] font-normal leading-[1.15] tracking-tight text-foreground sm:text-[26px] lg:text-[28px]">
        {study.headline}
      </h3>

      <div className="space-y-5 pt-1">
        <CaseStudyDottedSeparator />

        <blockquote className="max-w-2xl text-[13px] leading-6 text-foreground sm:text-[14px] sm:leading-7">
          &ldquo;{study.blurb}&rdquo;
        </blockquote>

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex min-w-0 items-center gap-2.5">
            <Avatar className="size-8">
              <AvatarImage src={study.avatar} alt="" />
              <AvatarFallback className="text-[11px]">{initials}</AvatarFallback>
            </Avatar>
            <p className="min-w-0 text-[13px] leading-5 text-foreground">
              <span className="font-medium">{study.name}</span>
              <span className="text-muted-foreground">
                , {study.title} @ {study.company}
              </span>
            </p>
          </div>

          <a
            href={study.storyUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="group inline-flex shrink-0 items-center gap-1.5 text-[13px] font-medium text-foreground transition-colors hover:text-[var(--brand-cta)]"
          >
            Read customer story
            <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
          </a>
        </div>
      </div>
    </div>
  )
}

function CaseStudyCard({
  study,
  isActive,
  onSelect,
}: {
  study: HomeCaseStudy
  isActive: boolean
  onSelect: () => void
}) {
  const [showPanelContent, setShowPanelContent] = useState(() => isActive)
  const tabId = `case-study-tab-${study.id}`
  const panelId = `case-study-panel-${study.id}`

  useEffect(() => {
    if (!isActive) {
      setShowPanelContent(false)
      return
    }

    const timeoutId = window.setTimeout(() => {
      setShowPanelContent(true)
    }, PANEL_RESIZE_MS)

    return () => window.clearTimeout(timeoutId)
  }, [isActive])

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (!isActive && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault()
      onSelect()
    }
  }

  return (
    <div
      className={cn(
        'min-w-0 w-full shrink max-lg:my-1.5 max-lg:flex-none lg:my-0',
        'motion-reduce:transition-none lg:transition-[flex-grow,flex-basis] lg:duration-300 lg:ease-in-out',
        isActive ? 'lg:flex-[7]' : 'lg:flex-[1.5]',
      )}
    >
      <div
        id={tabId}
        role="tab"
        tabIndex={isActive ? 0 : -1}
        aria-selected={isActive}
        aria-controls={panelId}
        aria-expanded={isActive}
        onClick={() => {
          if (!isActive) onSelect()
        }}
        onKeyDown={handleKeyDown}
        className={cn(
          'group relative isolate z-[1] w-full min-w-0 overflow-hidden rounded-xl border border-border bg-card',
          'transition-colors duration-300 ease-in-out motion-reduce:transition-none',
          'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
          !isActive && 'cursor-pointer hover:bg-accent/30',
          !isActive && 'h-16 lg:h-[467px] lg:max-h-[467px] lg:min-h-[467px]',
          isActive && 'lg:h-[467px] lg:max-h-[467px] lg:min-h-[467px]',
          isActive &&
            'lg:shadow-[0_0_0_4px_color-mix(in_srgb,var(--border)_65%,transparent)]',
        )}
      >
        {isActive ? (
          <div
            className={cn(
              'lg:hidden',
              'transition-opacity duration-200 ease-out motion-reduce:transition-none',
              showPanelContent ? 'opacity-100' : 'opacity-0',
            )}
          >
            <CaseStudyPanelContent study={study} />
          </div>
        ) : (
          <div
            className="flex h-16 items-center justify-center px-4 lg:hidden"
            aria-hidden={isActive}
          >
            <TrustedByLogo
              src={study.logo}
              alt={study.company}
              width={study.logoWidth}
              height={study.logoHeight}
              mask={study.logoMask}
              className="h-[1.125rem] w-auto sm:h-5 group-hover:scale-100"
            />
          </div>
        )}

        <div className="hidden lg:grid lg:h-full lg:min-h-0 lg:[grid-template-areas:stack]">
          <div
            className={cn(
              'flex items-center justify-center [grid-area:stack] p-8 transition-opacity duration-200',
              isActive ? 'pointer-events-none opacity-0' : 'opacity-100',
            )}
            aria-hidden={isActive}
          >
            <TrustedByLogo
              src={study.logo}
              alt={study.company}
              width={study.logoWidth}
              height={study.logoHeight}
              mask={study.logoMask}
              className="h-8 w-auto group-hover:scale-100"
            />
          </div>

          <div
            className={cn(
              'relative flex min-h-0 items-stretch justify-start overflow-hidden [grid-area:stack]',
              !isActive && 'pointer-events-none',
            )}
            aria-hidden={!isActive}
          >
            <div
              className={cn(
                'transition-opacity duration-200 ease-out motion-reduce:transition-none',
                showPanelContent && isActive ? 'opacity-100' : 'opacity-0',
              )}
            >
              <CaseStudyPanelContent study={study} />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export function TestimonialsSection() {
  const [activeId, setActiveId] = useState(homeCaseStudies[0]?.id ?? '')

  const handleTabListKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const currentIndex = homeCaseStudies.findIndex((study) => study.id === activeId)
    if (currentIndex < 0) return

    let nextIndex = currentIndex
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      nextIndex = (currentIndex + 1) % homeCaseStudies.length
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      nextIndex = (currentIndex - 1 + homeCaseStudies.length) % homeCaseStudies.length
    } else {
      return
    }

    event.preventDefault()
    const nextStudy = homeCaseStudies[nextIndex]
    if (nextStudy) setActiveId(nextStudy.id)
  }

  return (
    <section className="relative isolate overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <HomeSoftLights variant="testimonials" />
      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div
          role="tablist"
          aria-label="Customer stories"
          onKeyDown={handleTabListKeyDown}
          className="relative z-[1] flex w-full touch-pan-y flex-col max-lg:gap-0 overscroll-y-auto lg:min-h-[467px] lg:flex-row lg:items-stretch lg:gap-4"
        >
          {homeCaseStudies.map((study) => (
            <CaseStudyCard
              key={study.id}
              study={study}
              isActive={activeId === study.id}
              onSelect={() => setActiveId(study.id)}
            />
          ))}
        </div>
      </div>
    </section>
  )
}

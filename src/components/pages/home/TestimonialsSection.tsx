import { useEffect, useState, type KeyboardEvent } from 'react'
import { ArrowRight } from 'lucide-react'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { pickRandomHomeCaseStudies, type HomeCaseStudy } from '@/lib/home/case-studies'
import {
  HOME_LOGO_GRID_COUNT,
  pickRandomHomeLogos,
  type HomeCustomerLogo,
} from '@/lib/home/customer-logos'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { cn } from '@/lib/utils'
import { HomeSoftLights } from './HomeSoftLights'

const PANEL_RESIZE_MS = 420
const LOGO_GRID_ROWS = 2
const LOGO_ROTATE_MS = 6000

const accordionEase = 'cubic-bezier(0.22, 1, 0.36, 1)'
const accordionTransition = `600ms ${accordionEase}`

/** Shared logo sizing for compact cards (grid + collapsed accordion). */
const smallCardLogoClassName = 'h-5 w-auto max-h-none sm:h-6 lg:h-7'

const collapsedAccordionLogoClassName = 'h-10 w-auto max-h-none lg:h-11'

const SMALL_LOGO_SCALE = 1.25

function SmallCardLogo({
  logo,
  className = smallCardLogoClassName,
}: {
  logo: Pick<HomeCustomerLogo, 'src' | 'alt' | 'width' | 'height' | 'mask' | 'maskSrc' | 'inverseMask'>
  className?: string
}) {
  const usesMask = logo.mask || logo.inverseMask

  return (
    <TrustedByLogo
      src={logo.src}
      alt={logo.alt}
      width={usesMask ? Math.round(logo.width * SMALL_LOGO_SCALE) : logo.width}
      height={usesMask ? Math.round(logo.height * SMALL_LOGO_SCALE) : logo.height}
      mask={logo.mask}
      maskSrc={logo.maskSrc}
      inverseMask={logo.inverseMask}
      interactive={false}
      className={className}
    />
  )
}

function useLogoGridColumns() {
  const isLg = useMediaMinWidth(1024)
  const isSm = useMediaMinWidth(640)
  if (isLg) return 6
  if (isSm) return 3
  return 2
}

function CustomerLogoGrid({ logos }: { logos: HomeCustomerLogo[] }) {
  const columns = useLogoGridColumns()
  const logosPerPage = columns * LOGO_GRID_ROWS
  const totalPages = Math.max(1, Math.ceil(logos.length / logosPerPage))
  const [pageIndex, setPageIndex] = useState(0)

  useEffect(() => {
    setPageIndex(0)
  }, [logosPerPage, logos.length])

  useEffect(() => {
    if (totalPages <= 1) return

    const intervalId = window.setInterval(() => {
      setPageIndex((current) => (current + 1) % totalPages)
    }, LOGO_ROTATE_MS)

    return () => window.clearInterval(intervalId)
  }, [totalPages])

  const visibleBatch = logos.slice(pageIndex * logosPerPage, (pageIndex + 1) * logosPerPage)
  const prefersReducedMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches

  return (
    <div
      key={pageIndex}
      className={cn(
        'relative z-[1] col-span-6 mt-3 grid min-h-[calc(2*4rem+0.75rem)] grid-cols-2 gap-3 sm:grid-cols-3 lg:mt-4 lg:min-h-[calc(2*5rem+1rem)] lg:grid-cols-subgrid lg:gap-4',
        !prefersReducedMotion && 'motion-reduce:animate-none animate-in fade-in duration-500',
      )}
      aria-label="More customers"
      aria-live="polite"
    >
      {visibleBatch.map((logo) => (
        <CustomerLogoCard key={logo.src} logo={logo} />
      ))}
    </div>
  )
}

function CaseStudyDottedSeparator() {
  return (
    <div
      aria-hidden
      className="h-px w-full bg-[repeating-linear-gradient(90deg,var(--border)_0,var(--border)_2px,transparent_2px,transparent_7px)]"
    />
  )
}

function CaseStudyPanelContent({
  study,
  revealed = true,
}: {
  study: HomeCaseStudy
  revealed?: boolean
}) {
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
      className="flex w-[min(100%,36rem)] min-w-[17.5rem] max-w-none flex-col gap-5 p-6 text-left lg:w-full lg:min-w-0 lg:p-10"
    >
      <div
        className={cn(
          'flex h-6 w-full max-w-[min(100%,150px)] items-center motion-reduce:transition-none sm:h-7 md:h-8',
          revealed ? 'opacity-100' : 'opacity-0',
        )}
        style={{
          transition: `opacity 500ms ${accordionEase}`,
        }}
      >
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

      <h3
        className={cn(
          'font-aeonik-pro max-w-[20ch] text-pretty text-[22px] font-normal leading-[1.15] tracking-tight text-foreground motion-reduce:transition-none sm:text-[26px] lg:text-[28px]',
          revealed ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0',
        )}
        style={{
          transition: `opacity 650ms ${accordionEase}, transform 650ms ${accordionEase}`,
          transitionDelay: revealed ? '60ms' : '0ms',
        }}
      >
        {study.headline}
      </h3>

      <div
        className={cn(
          'space-y-5 pt-1 motion-reduce:transition-none',
          revealed ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0',
        )}
        style={{
          transition: `opacity 650ms ${accordionEase}, transform 650ms ${accordionEase}`,
          transitionDelay: revealed ? '140ms' : '0ms',
        }}
      >
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

function CustomerLogoCard({ logo }: { logo: HomeCustomerLogo }) {
  return (
    <div className="flex h-16 w-full min-w-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-card px-3 lg:h-20 lg:px-4">
      <SmallCardLogo logo={logo} />
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
        'min-w-0 w-full shrink max-lg:my-1.5 max-lg:flex-none lg:my-0 lg:min-w-0',
        isActive ? 'lg:col-span-4' : 'lg:col-span-1',
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
          'motion-reduce:transition-none',
          'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
          !isActive && 'cursor-pointer hover:bg-accent/30',
          !isActive && 'h-16 lg:h-[467px] lg:max-h-[467px] lg:min-h-[467px]',
          isActive && 'lg:h-[467px] lg:max-h-[467px] lg:min-h-[467px]',
          isActive &&
            'lg:shadow-[0_0_0_4px_color-mix(in_srgb,var(--border)_65%,transparent)]',
        )}
        style={
          {
            transition: `background-color ${accordionTransition}, box-shadow ${accordionTransition}`,
          } as const
        }
      >
        {isActive ? (
          <div
            className={cn(
              'lg:hidden',
              'motion-reduce:transition-none',
              showPanelContent
                ? 'translate-y-0 opacity-100'
                : 'translate-y-2 opacity-0',
            )}
            style={{ transition: `opacity 550ms ${accordionEase}, transform 550ms ${accordionEase}` }}
          >
            <CaseStudyPanelContent study={study} revealed={showPanelContent} />
          </div>
        ) : (
          <div
            className="flex h-16 items-center justify-center px-4 lg:hidden"
            aria-hidden={isActive}
          >
            <SmallCardLogo
              logo={{
                src: study.logo,
                alt: study.company,
                width: study.logoWidth,
                height: study.logoHeight,
                mask: study.logoMask,
              }}
            />
          </div>
        )}

        <div className="hidden lg:grid lg:h-full lg:min-h-0 lg:[grid-template-areas:stack]">
          <div
            className={cn(
              'flex items-center justify-center [grid-area:stack] p-8 motion-reduce:transition-none',
              isActive ? 'pointer-events-none opacity-0' : 'opacity-100',
            )}
            style={{
              transition: `opacity 500ms ${accordionEase}`,
              transitionDelay: isActive ? '0ms' : '120ms',
            }}
            aria-hidden={isActive}
          >
            <SmallCardLogo
              logo={{
                src: study.logo,
                alt: study.company,
                width: study.logoWidth,
                height: study.logoHeight,
                mask: study.logoMask,
              }}
              className={collapsedAccordionLogoClassName}
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
                'motion-reduce:transition-none',
                showPanelContent && isActive
                  ? 'translate-y-0 opacity-100'
                  : 'translate-y-2 opacity-0',
              )}
              style={{ transition: `opacity 600ms ${accordionEase}, transform 600ms ${accordionEase}` }}
            >
              <CaseStudyPanelContent study={study} revealed={showPanelContent && isActive} />
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export function TestimonialsSection() {
  const [mounted, setMounted] = useState(false)
  const [visibleStudies, setVisibleStudies] = useState<HomeCaseStudy[]>([])
  const [gridLogos, setGridLogos] = useState<HomeCustomerLogo[]>([])
  const [activeId, setActiveId] = useState('')

  useEffect(() => {
    const studies = pickRandomHomeCaseStudies()
    setVisibleStudies(studies)
    setActiveId(studies[0]?.id ?? '')
    setGridLogos(pickRandomHomeLogos(HOME_LOGO_GRID_COUNT))
    setMounted(true)
  }, [])

  const handleTabListKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const currentIndex = visibleStudies.findIndex((study) => study.id === activeId)
    if (currentIndex < 0) return

    let nextIndex = currentIndex
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      nextIndex = (currentIndex + 1) % visibleStudies.length
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      nextIndex = (currentIndex - 1 + visibleStudies.length) % visibleStudies.length
    } else {
      return
    }

    event.preventDefault()
    const nextStudy = visibleStudies[nextIndex]
    if (nextStudy) setActiveId(nextStudy.id)
  }

  return (
    <section className="relative isolate overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <HomeSoftLights variant="testimonials" />
      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="mx-auto mb-10 max-w-3xl text-center sm:mb-12">
          <h2 className="font-aeonik-pro text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            Loved by teams building in production
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mx-auto mt-5 max-w-2xl text-balance text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7">
            From fast-moving startups to global enterprises, developers ship faster
            with Appwrite.
          </p>
        </div>

        {mounted ? (
          <div className="lg:grid lg:grid-cols-6 lg:gap-4">
            <div
              role="tablist"
              aria-label="Customer stories"
              onKeyDown={handleTabListKeyDown}
              className="relative z-[1] col-span-6 flex w-full touch-pan-y flex-col max-lg:gap-0 overscroll-y-auto lg:grid lg:min-h-[467px] lg:grid-cols-subgrid lg:items-stretch lg:gap-4"
            >
              {visibleStudies.map((study) => (
                <CaseStudyCard
                  key={study.id}
                  study={study}
                  isActive={activeId === study.id}
                  onSelect={() => setActiveId(study.id)}
                />
              ))}
            </div>

            {gridLogos.length > 0 ? <CustomerLogoGrid logos={gridLogos} /> : null}

            <div className="relative z-[1] col-span-6 mt-8 text-center lg:mt-10">
              <a
                href="https://appwrite.io/blog/category/customer-stories"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-[13px] font-medium text-foreground transition-colors hover:text-[var(--brand-cta)]"
              >
                Read our case studies
                <ArrowRight className="size-3.5" />
              </a>
            </div>
          </div>
        ) : (
          <div
            className="max-lg:min-h-[40rem] lg:min-h-[467px]"
            aria-hidden
          />
        )}
      </div>
    </section>
  )
}

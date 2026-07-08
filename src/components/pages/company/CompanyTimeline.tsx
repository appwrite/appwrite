import { Fragment, useEffect, useMemo, useState } from 'react'
import { COMPANY_SECTION_IDS } from '@/lib/company/sections'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import { ImagePreviewGalleryDialog } from '@/components/global/shared/ImagePreviewGallery'
import {
  companyTimelineIntro,
  companyTimelineMilestones,
  companyTimelineYearGroups,
  companyTimelineYears,
  type CompanyTimelineLink,
  type CompanyTimelineImage,
  type CompanyTimelineMilestone,
} from '@/lib/company/timeline'
import {
  getTimelineYearAnchorId,
  scrollToTimelineYear,
  scrollToTimelineYearFromHash,
} from '@/lib/company/timeline-scroll'
import { cn } from '@/lib/utils'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import {
  getSiteLinkInternalPath,
  isSiteLinkExternal,
  parseBlogPagePath,
  parseDocsPagePath,
  parseMarketingSitePagePath,
  resolveSiteLinkUrl,
} from '@/lib/marketing/urls'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import {
  ArrowUpRight,
  BookOpen,
  Github,
  Megaphone,
  Newspaper,
  Rocket,
  type LucideIcon,
} from 'lucide-react'

const LINK_KIND_META: Record<
  CompanyTimelineLink['kind'],
  { typeLabel: string; icon?: LucideIcon; iconSrc?: string }
> = {
  blog: { icon: Newspaper, typeLabel: 'Blog' },
  docs: { icon: BookOpen, typeLabel: 'Docs' },
  github: { icon: Github, typeLabel: 'GitHub' },
  news: { icon: Megaphone, typeLabel: 'News' },
  product: { icon: Rocket, typeLabel: 'Product' },
  'product-hunt': {
    iconSrc: '/icons/product-hunt.svg',
    typeLabel: 'Product Hunt',
  },
  youtube: { iconSrc: '/icons/youtube.svg', typeLabel: 'YouTube' },
}

function MilestoneLinkContent({
  link,
  typeLabel,
  icon: Icon,
  iconSrc,
}: {
  link: CompanyTimelineLink
  typeLabel: string
  icon?: LucideIcon
  iconSrc?: string
}) {
  const t = useT()
  return (
    <>
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        {iconSrc ? (
          <img src={iconSrc} alt="" className="size-3.5" aria-hidden />
        ) : Icon ? (
          <Icon className="size-3.5" aria-hidden />
        ) : null}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(typeLabel)}
        </span>
        <span className="mt-0.5 block text-[12px] font-medium leading-snug text-foreground">
          {t(link.label)}
        </span>
      </span>
      <ArrowUpRight
        className="size-3.5 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground"
        aria-hidden
      />
    </>
  )
}

function MilestoneLinks({ links }: { links: readonly CompanyTimelineLink[] }) {
  const { features } = useConsoleProfile()

  if (links.length === 0) return null

  const linkClassName =
    'group flex items-center gap-2.5 px-3 py-2 transition-colors hover:bg-accent/30'

  return (
    <ul className="mt-3 overflow-hidden rounded-lg border border-border">
      {links.map((link) => {
        const { icon: Icon, iconSrc, typeLabel } = LINK_KIND_META[link.kind]

        return (
          <li key={link.id} className="border-b border-border last:border-b-0">
            {parseBlogPagePath(link.href) ? (
              <BlogPageAnchor href={link.href} className={linkClassName}>
                <MilestoneLinkContent
                  link={link}
                  typeLabel={typeLabel}
                  icon={Icon}
                  iconSrc={iconSrc}
                />
              </BlogPageAnchor>
            ) : parseDocsPagePath(link.href) ? (
              <DocsRouteLink href={link.href} className={linkClassName}>
                <MilestoneLinkContent
                  link={link}
                  typeLabel={typeLabel}
                  icon={Icon}
                  iconSrc={iconSrc}
                />
              </DocsRouteLink>
            ) : parseMarketingSitePagePath(link.href) ||
              getSiteLinkInternalPath(link.href) ? (
              <MarketingSiteLink href={link.href} className={linkClassName}>
                <MilestoneLinkContent
                  link={link}
                  typeLabel={typeLabel}
                  icon={Icon}
                  iconSrc={iconSrc}
                />
              </MarketingSiteLink>
            ) : (
              <a
                href={resolveSiteLinkUrl(link.href, features.marketing)}
                {...(isSiteLinkExternal(link.href, features.marketing)
                  ? { target: '_blank', rel: 'noopener noreferrer' }
                  : {})}
                className={linkClassName}
              >
                <MilestoneLinkContent
                  link={link}
                  typeLabel={typeLabel}
                  icon={Icon}
                  iconSrc={iconSrc}
                />
              </a>
            )}
          </li>
        )
      })}
    </ul>
  )
}

function MilestoneImages({ images }: { images: readonly CompanyTimelineImage[] }) {
  const t = useT()
  const [previewIndex, setPreviewIndex] = useState<number | null>(null)

  if (images.length === 0) return null

  return (
    <>
      <ul className="mt-3 flex flex-wrap gap-2 border-t border-border pt-3">
        {images.map((image, index) => (
          <li key={image.src}>
            <button
              type="button"
              onClick={() => setPreviewIndex(index)}
              className="block cursor-pointer overflow-hidden rounded-md border border-border bg-muted/20 transition-colors hover:border-foreground/20 hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              aria-label={`${t('Enlarge image:')} ${image.alt}`}
            >
              <img
                src={image.src}
                alt=""
                className="h-14 w-[4.5rem] object-cover object-center sm:h-16 sm:w-24"
                loading="lazy"
                decoding="async"
              />
            </button>
          </li>
        ))}
      </ul>

      <ImagePreviewGalleryDialog
        items={images}
        activeIndex={previewIndex}
        onActiveIndexChange={setPreviewIndex}
      />
    </>
  )
}

function MilestoneCard({
  title,
  description,
  links,
  images,
  className,
}: {
  title: string
  description: string
  links?: readonly CompanyTimelineLink[]
  images?: readonly CompanyTimelineImage[]
  className?: string
}) {
  const t = useT()
  const milestoneImages = images ?? []
  const hasLinks = links && links.length > 0

  return (
    <article
      className={cn(
        'w-full max-w-md rounded-xl border border-border bg-card p-4 shadow-sm sm:p-5',
        className,
      )}
    >
      <h3 className="text-[14px] font-semibold leading-snug text-foreground sm:text-[15px]">
        {t(title)}
      </h3>
      <p className="mt-2 text-[13px] leading-6 text-muted-foreground sm:text-[14px]">
        {t(description)}
      </p>
      {hasLinks ? <MilestoneLinks links={links} /> : null}
      {milestoneImages.length > 0 ? (
        <MilestoneImages images={milestoneImages} />
      ) : null}
    </article>
  )
}

function TimelineDate({
  date,
  highlighted = false,
  className,
}: {
  date: string
  highlighted?: boolean
  className?: string
}) {
  return (
    <time
      dateTime={date}
      className={cn(
        'inline-block shrink-0 self-start whitespace-nowrap text-[12px] font-medium leading-none sm:text-[13px]',
        highlighted
          ? 'rounded-full bg-foreground px-3 py-1.5 text-[11px] text-background sm:text-[12px]'
          : 'text-muted-foreground',
        className,
      )}
    >
      {date}
    </time>
  )
}

function TimelineYearNav() {
  const t = useT()
  return (
    <nav
      aria-label={t('Jump to timeline year')}
      className="mx-auto flex max-w-2xl flex-wrap items-center justify-center gap-2"
    >
      {companyTimelineYears.map((year) => (
        <button
          key={year}
          type="button"
          onClick={() => scrollToTimelineYear(year)}
          className="inline-flex h-8 min-w-14 cursor-pointer items-center justify-center rounded-full border border-border bg-card/50 px-3 text-[12px] font-medium text-muted-foreground transition-colors hover:border-border hover:bg-accent/50 hover:text-foreground"
        >
          {year}
        </button>
      ))}
    </nav>
  )
}

function TimelineYearMarker({
  year,
  variant,
}: {
  year: number
  variant: 'mobile' | 'desktop'
}) {
  if (variant === 'mobile') {
    return (
      <li
        data-timeline-year={year}
        id={getTimelineYearAnchorId(year)}
        className="relative scroll-mt-28 pb-3 pt-1 first:pt-0"
      >
        <div className="ps-8">
          <span className="inline-flex rounded-full bg-muted px-3 py-1 text-[11px] font-semibold tracking-wide text-muted-foreground">
            {year}
          </span>
        </div>
      </li>
    )
  }

  return (
    <li
      data-timeline-year={year}
      className="relative scroll-mt-28 pb-3 pt-1 first:pt-0"
    >
      <div className="flex justify-center py-0.5">
        <span className="relative z-10 inline-flex rounded-full bg-background px-3 py-1 text-[11px] font-semibold tracking-wide text-muted-foreground shadow-sm">
          {year}
        </span>
      </div>
    </li>
  )
}

type TimelineEntryProps = {
  index: number
  milestone: CompanyTimelineMilestone
}

function MobileTimelineEntry({ index, milestone }: TimelineEntryProps) {
  return (
    <li className="relative pb-8 last:pb-0">
      <span
        aria-hidden
        className="absolute start-3 top-2 z-[1] size-2 -translate-x-1/2 rounded-full bg-muted-foreground/50 ring-[3px] ring-background"
      />
      <div className="flex flex-col gap-3 ps-8">
        <TimelineDate date={milestone.date} highlighted={index === 0} />
        <MilestoneCard
          title={milestone.title}
          description={milestone.description}
          links={milestone.links}
          images={milestone.images}
        />
      </div>
    </li>
  )
}

function DesktopTimelineEntry({ index, milestone }: TimelineEntryProps) {
  const cardOnRight = index % 2 === 0

  return (
    <li className="relative pb-10 last:pb-0">
      <div className="pointer-events-none absolute left-1/2 top-2.5 z-[1] -translate-x-1/2">
        <span
          aria-hidden
          className="block size-2 rounded-full bg-muted-foreground/50 ring-[3px] ring-background"
        />
      </div>

      <div className="flex w-full items-start">
        <div className="flex w-1/2 min-w-0 justify-end pe-6 lg:pe-8">
          {cardOnRight ? (
            <TimelineDate date={milestone.date} highlighted={index === 0} />
          ) : (
            <div className="relative w-full max-w-md">
              <span
                aria-hidden
                className="absolute end-0 top-3 h-px w-6 ltr:translate-x-full rtl:-translate-x-full bg-border lg:w-8"
              />
              <MilestoneCard
                title={milestone.title}
                description={milestone.description}
                links={milestone.links}
                images={milestone.images}
              />
            </div>
          )}
        </div>

        <div className="flex w-1/2 min-w-0 justify-start ps-6 lg:ps-8">
          {cardOnRight ? (
            <div className="relative w-full max-w-md">
              <span
                aria-hidden
                className="absolute start-0 top-3 h-px w-6 ltr:-translate-x-full rtl:translate-x-full bg-border lg:w-8"
              />
              <MilestoneCard
                title={milestone.title}
                description={milestone.description}
                links={milestone.links}
                images={milestone.images}
              />
            </div>
          ) : (
            <TimelineDate date={milestone.date} highlighted={index === 0} />
          )}
        </div>
      </div>
    </li>
  )
}

function useTimelineEntryIndex() {
  return useMemo(() => {
    const indexById = new Map<string, number>()
    companyTimelineMilestones.forEach((milestone, index) => {
      indexById.set(milestone.id, index)
    })
    return indexById
  }, [])
}

export function CompanyTimeline() {
  const t = useT()
  const milestoneIndexById = useTimelineEntryIndex()

  useEffect(() => {
    scrollToTimelineYearFromHash()
  }, [])

  return (
    <section
      id={COMPANY_SECTION_IDS.story}
      className="relative isolate scroll-mt-28 overflow-x-hidden border-b border-border bg-background"
    >
      <div
        className="pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-70"
        aria-hidden
      />
      <div className="@container relative z-[1] mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-16">
        <PricingSectionHeading
          size="md"
          title={t('Our story')}
          description={t(companyTimelineIntro)}
          className="mx-auto max-w-2xl"
        />

        <div className="mt-8 sm:mt-10">
          <TimelineYearNav />
        </div>

        <div className="relative mt-8 sm:mt-10">
          {/* Narrow: stacked timeline with left rail */}
          <div className="@[768px]:hidden">
            <span
              aria-hidden
              className="pointer-events-none absolute start-3 top-0 bottom-0 z-0 w-px -translate-x-1/2 bg-border"
            />
            <ol className="relative z-[1]">
              {companyTimelineYearGroups.map((group) => (
                <Fragment key={group.year}>
                  <TimelineYearMarker year={group.year} variant="mobile" />
                  {group.milestones.map((milestone) => (
                    <MobileTimelineEntry
                      key={milestone.id}
                      index={milestoneIndexById.get(milestone.id) ?? 0}
                      milestone={milestone}
                    />
                  ))}
                </Fragment>
              ))}
            </ol>
          </div>

          {/* Wide: centered spine with alternating cards */}
          <div className="hidden @[768px]:block">
            <span
              aria-hidden
              className="pointer-events-none absolute left-1/2 top-0 bottom-0 z-0 w-px -translate-x-1/2 bg-border"
            />
            <ol className="relative z-[1]">
              {companyTimelineYearGroups.map((group) => (
                <Fragment key={group.year}>
                  <TimelineYearMarker year={group.year} variant="desktop" />
                  {group.milestones.map((milestone) => (
                    <DesktopTimelineEntry
                      key={milestone.id}
                      index={milestoneIndexById.get(milestone.id) ?? 0}
                      milestone={milestone}
                    />
                  ))}
                </Fragment>
              ))}
            </ol>
          </div>
        </div>
      </div>
    </section>
  )
}

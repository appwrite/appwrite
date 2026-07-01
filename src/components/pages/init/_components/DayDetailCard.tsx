import type {
  LaunchEventDay,
  LaunchEventDayResource,
  LaunchEventScheduleItem,
  InitDisplayEvent,
} from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitDayPreviewActivity } from '@/lib/init/init-presence-day-activity'
import { getInitDayCardId } from '@/lib/init/scroll-to-day-card'
import { InitDayMockVisual } from './InitDayMockVisual'
import { Badge } from '@/components/ui/badge'
import { InitDayCardHeaderNav } from './InitDayCardHeaderNav'
import { InitScheduleRow } from './InitScheduleRow'
import { ArrowUpRight, BookOpen, FileText, Play } from 'lucide-react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { isExternalInitHref } from '@/lib/init/links'
import { parseBlogPagePath, parseDocsPagePath } from '@/lib/marketing/urls'
import { cn } from '@/lib/utils'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

function VideoThumbnail({
  label,
  href,
  className,
}: {
  label: string
  href?: string
  className?: string
}) {
  const inner = (
    <>
      <div
        className={cn(
          'relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/40',
          className,
        )}
      >
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,color-mix(in_srgb,var(--brand-cta)_12%,transparent),transparent_55%)]" />
        <span className="relative flex size-9 items-center justify-center rounded-full bg-background/80 text-foreground shadow-sm backdrop-blur-sm">
          <Play className="ms-0.5 size-4 fill-current" aria-hidden />
        </span>
      </div>
      <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
    </>
  )

  if (href) {
    const external = isExternalInitHref(href)
    return (
      <a
        href={href}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className="group block transition-opacity hover:opacity-90"
      >
        {inner}
      </a>
    )
  }

  return <div>{inner}</div>
}

function DayVisual({ day }: { day: LaunchEventDay }) {
  const Icon = day.icon

  if (day.visual) {
    return (
      <InitDayMockVisual
        mockVisualId={day.visual.mockVisualId}
        label={day.visual.imageAlt}
      />
    )
  }

  return (
    <div
      aria-hidden
      className="relative flex min-h-[200px] flex-1 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/30 lg:min-h-[240px]"
    >
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,color-mix(in_srgb,var(--brand-cta)_10%,transparent),transparent_65%)]" />
      <div className="absolute inset-0 opacity-30 [background-image:radial-gradient(circle,color-mix(in_srgb,var(--border)_80%,transparent)_1px,transparent_1px)] [background-size:16px_16px]" />
      <div className="relative flex size-24 items-center justify-center rounded-full border border-[color-mix(in_srgb,var(--brand-cta)_25%,var(--border))] bg-background/60 shadow-sm backdrop-blur-sm">
        <Icon className="size-10 text-muted-foreground" />
      </div>
      <div className="absolute end-[18%] top-[28%] size-3 rounded-full bg-[var(--brand-cta)] shadow-[0_0_12px_color-mix(in_srgb,var(--brand-cta)_60%,transparent)]" />
    </div>
  )
}

const TITLE_CLASS =
  'font-aeonik-pro text-[clamp(32px,4.5vw,42px)] font-normal leading-none tracking-tight'

function DayTitle({ title }: { title: string }) {
  const lastSpace = title.lastIndexOf(' ')
  const leading = lastSpace === -1 ? '' : title.slice(0, lastSpace + 1)
  const lastWord = lastSpace === -1 ? title : title.slice(lastSpace + 1)

  return (
    <h3 className={cn(TITLE_CLASS, 'text-foreground')}>
      {leading ? <span>{leading}</span> : null}
      <span className="whitespace-nowrap">
        {lastWord}
        <span className="text-[var(--brand-cta)]">_</span>
      </span>
    </h3>
  )
}

interface DayDetailCardProps {
  event: InitDisplayEvent
  day: LaunchEventDay
  scheduleItems: LaunchEventScheduleItem[]
  currentDay: number
  isRecapMode?: boolean
}

function DayResourceRow({ resource }: { resource: LaunchEventDayResource }) {
  const type = resource.typeLabel.toLowerCase()
  const ResourceIcon = type === 'docs' ? BookOpen : FileText
  const isBlogLink = Boolean(parseBlogPagePath(resource.href))
  const isDocsLink = Boolean(parseDocsPagePath(resource.href))
  const rowClassName =
    'group flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-accent/30'
  const rowContent = (
    <>
      <span className="flex w-[92px] shrink-0">
        <Badge
          variant="secondary"
          className="gap-1.5 rounded-md bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
        >
          <ResourceIcon className="size-3 text-muted-foreground" aria-hidden />
          {resource.typeLabel}
        </Badge>
      </span>
      <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
        {resource.title}
      </span>
      <span className="flex shrink-0 items-center gap-1 text-[12px] text-muted-foreground transition-colors group-hover:text-foreground">
        {resource.actionLabel}
        <ArrowUpRight className="size-3.5" aria-hidden />
      </span>
    </>
  )

  return (
    <li>
      {isBlogLink ? (
        <BlogPageAnchor href={resource.href} className={rowClassName}>
          {rowContent}
        </BlogPageAnchor>
      ) : isDocsLink ? (
        <DocsRouteLink href={resource.href} className={rowClassName}>
          {rowContent}
        </DocsRouteLink>
      ) : (
        <a
          href={resource.href}
          {...(isExternalInitHref(resource.href)
            ? { target: '_blank', rel: 'noopener noreferrer' }
            : {})}
          className={rowClassName}
        >
          {rowContent}
        </a>
      )}
    </li>
  )
}

export function DayDetailCard({
  event,
  day,
  scheduleItems,
  currentDay,
  isRecapMode = false,
}: DayDetailCardProps) {
  const { setTransientActivity } = useInitPresenceActivity()
  const blogResources = day.resources.filter(
    (resource) => resource.typeLabel.toLowerCase() === 'blog',
  )
  const otherResources = day.resources.filter(
    (resource) => resource.typeLabel.toLowerCase() !== 'blog',
  )
  const hasListContent =
    blogResources.length > 0 ||
    scheduleItems.length > 0 ||
    otherResources.length > 0
  const footerVideos = day.footerVideos ?? []

  return (
    <article
      id={getInitDayCardId(day.day)}
      className={cn(CARD_SHELL, 'scroll-mt-28')}
      onMouseEnter={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onMouseLeave={() => setTransientActivity(null)}
    >
      <header className="grid grid-cols-[1fr_auto_1fr] items-center border-b border-border px-6 py-3">
        <span aria-hidden />
        <p className="text-center text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Day {day.day} / {day.weekdayLabel}
        </p>
        <div className="flex items-center justify-end gap-2">
          {!isRecapMode && day.isLive ? (
            <Badge variant="error" className="text-[10px] shrink-0">
              Live
            </Badge>
          ) : null}
          <InitDayCardHeaderNav day={day} />
        </div>
      </header>

      <div className="grid lg:grid-cols-2">
        <div className="space-y-5 border-b border-border px-6 py-6 lg:border-b-0 lg:border-e">
          <div>
            <DayTitle title={day.title} />
            <p className="mt-4 text-[13px] leading-relaxed text-muted-foreground">
              {day.longDescription}
            </p>
          </div>
        </div>

        <div className="flex items-center p-6">
          <DayVisual day={day} />
        </div>
      </div>

      {hasListContent ? (
        <ul className="divide-y divide-border border-t border-border">
          {blogResources.map((resource) => (
            <DayResourceRow key={resource.id} resource={resource} />
          ))}
          {scheduleItems.map((item) => (
            <InitScheduleRow
              key={item.id}
              event={event}
              item={item}
              isRecapMode={isRecapMode}
              inlineWhenWide
            />
          ))}
          {otherResources.map((resource) => (
            <DayResourceRow key={resource.id} resource={resource} />
          ))}
        </ul>
      ) : null}

      {footerVideos.length > 0 ? (
        <div className="grid gap-6 border-t border-border px-6 py-6 sm:grid-cols-2">
          {footerVideos.map((video) => (
            <VideoThumbnail
              key={video.id}
              label={video.label}
              href={video.href}
            />
          ))}
        </div>
      ) : null}
    </article>
  )
}

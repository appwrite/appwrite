import type { LaunchEventDay } from '@/lib/init/types'
import { Badge } from '@/components/ui/badge'
import { ArrowUpRight, Play } from 'lucide-react'
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
          <Play className="ml-0.5 size-4 fill-current" aria-hidden />
        </span>
      </div>
      <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
    </>
  )

  if (href) {
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
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
      <div className="absolute right-[18%] top-[28%] size-3 rounded-full bg-[var(--brand-cta)] shadow-[0_0_12px_color-mix(in_srgb,var(--brand-cta)_60%,transparent)]" />
    </div>
  )
}

interface DayDetailCardProps {
  day: LaunchEventDay
}

export function DayDetailCard({ day }: DayDetailCardProps) {
  return (
    <article id={`day-${day.day}`} className={CARD_SHELL}>
      <header className="border-b border-border px-6 py-3 text-center">
        <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Day {day.day} / {day.weekdayLabel}
        </p>
      </header>

      <div className="grid border-b border-border lg:grid-cols-2">
        <div className="space-y-5 border-b border-border px-6 py-6 lg:border-b-0 lg:border-r">
          <div>
            <div className="flex flex-wrap items-start gap-x-2 gap-y-1">
              <h3 className="text-[clamp(28px,4vw,36px)] font-semibold leading-none tracking-tight text-foreground">
                {day.title}
              </h3>
              <span className="text-[clamp(28px,4vw,36px)] font-semibold leading-none text-[var(--brand-cta)]">
                _
              </span>
              {day.isLive ? (
                <Badge variant="error" className="mt-1 text-[10px] shrink-0">
                  Live
                </Badge>
              ) : null}
            </div>
            <p className="mt-4 text-[13px] leading-relaxed text-muted-foreground">
              {day.longDescription}
            </p>
          </div>

          {day.announcementVideo ? (
            <VideoThumbnail
              label={day.announcementVideo.label}
              href={day.announcementVideo.href}
              className="max-w-[220px]"
            />
          ) : null}
        </div>

        <div className="flex p-6">
          <DayVisual day={day} />
        </div>
      </div>

      {day.resources.length > 0 ? (
        <ul className="divide-y divide-border border-b border-border">
          {day.resources.map((resource) => (
            <li key={resource.id}>
              <a
                href={resource.href}
                target="_blank"
                rel="noopener noreferrer"
                className="group flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-accent/30"
              >
                <Badge
                  variant="secondary"
                  className="shrink-0 rounded-md bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
                >
                  {resource.typeLabel}
                </Badge>
                <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
                  {resource.title}
                </span>
                <span className="flex shrink-0 items-center gap-1 text-[12px] text-muted-foreground transition-colors group-hover:text-foreground">
                  {resource.actionLabel}
                  <ArrowUpRight className="size-3.5" aria-hidden />
                </span>
              </a>
            </li>
          ))}
        </ul>
      ) : null}

      {day.footerVideos.length > 0 ? (
        <div className="grid gap-6 px-6 py-6 sm:grid-cols-2">
          {day.footerVideos.map((video) => (
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

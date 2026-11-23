import { Hash, Megaphone, Plus, Users } from 'lucide-react'
import { ArtChip, ArtIconBadge, floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Subscriber = { initials: string; className: string; shared?: boolean }

type TopicCircle = {
  id: string
  subscribers: string
  useCase: string
  circleClassName: string
  fillClassName: string
  labelClassName: string
  members: Subscriber[]
}

/** Circles overlap so shared subscribers sit in the intersections; member positions are relative to each circle. */
const TOPICS: TopicCircle[] = [
  {
    id: 'product-updates',
    subscribers: '1,248',
    useCase: 'Newsletters',
    circleClassName: 'start-[8%] top-[9%]',
    fillClassName: 'border-[rgb(var(--tone-rgb)/0.4)] bg-[rgb(var(--tone-rgb)/0.06)]',
    labelClassName: 'start-[8%] top-0 w-[46%]',
    members: [
      { initials: 'PD', className: 'start-[26%] top-[26%]' },
      { initials: 'HQ', className: 'start-[14%] top-[52%]' },
      { initials: 'TC', className: 'start-[44%] top-[16%]' },
      { initials: 'WO', className: 'start-[80%] top-[44%]', shared: true },
    ],
  },
  {
    id: 'security-alerts',
    subscribers: '892',
    useCase: 'Alerts',
    circleClassName: 'end-[8%] top-[9%]',
    fillClassName: 'border-[rgb(var(--tone2-rgb)/0.45)] bg-[rgb(var(--tone2-rgb)/0.06)]',
    labelClassName: 'end-[8%] top-0 w-[46%]',
    members: [
      { initials: 'SD', className: 'end-[26%] top-[24%]' },
      { initials: 'RK', className: 'end-[14%] top-[52%]' },
      { initials: 'MO', className: 'end-[46%] top-[14%]' },
    ],
  },
  {
    id: 'weekly-digest',
    subscribers: '3,401',
    useCase: 'Announcements',
    circleClassName: 'left-1/2 top-[38%] -translate-x-1/2',
    fillClassName: 'border-foreground/20 bg-foreground/[0.025]',
    labelClassName: 'bottom-0 left-1/2 w-[46%] -translate-x-1/2',
    members: [
      { initials: 'JL', className: 'start-[30%] top-[62%]' },
      { initials: 'AV', className: 'end-[28%] top-[66%]' },
      { initials: 'EB', className: 'start-[48%] top-[80%]' },
      { initials: 'NK', className: 'end-[12%] top-[34%]', shared: true },
    ],
  },
]

function Avatar({ subscriber, delayMs }: { subscriber: Subscriber; delayMs: number }) {
  return (
    <span className={cn('product-hero-rise absolute', subscriber.className)} style={riseStyle(delayMs)} aria-hidden>
      <span
        className={cn(
          'product-hero-float flex size-7 items-center justify-center rounded-full border text-[9px] font-semibold shadow-sm',
          subscriber.shared
            ? 'border-[rgb(var(--tone-rgb)/0.5)] bg-background text-[var(--tone-ink)] dark:bg-card'
            : 'border-border bg-background text-muted-foreground dark:bg-card',
        )}
        style={floatStyle(delayMs * 2)}
      >
        {subscriber.initials}
      </span>
    </span>
  )
}

export function MessagingTopicsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[340px] w-full max-w-[560px] sm:h-[440px]">
      {TOPICS.map((topic, topicIndex) => (
        <div
          key={topic.id}
          className={cn('product-hero-rise absolute aspect-square w-[46%]', topic.circleClassName)}
          style={riseStyle(80 + topicIndex * 160)}
        >
          <div className={cn('absolute inset-0 rounded-full border border-dashed', topic.fillClassName)} aria-hidden />
          {topic.members.map((member, memberIndex) => (
            <Avatar key={member.initials} subscriber={member} delayMs={400 + topicIndex * 160 + memberIndex * 90} />
          ))}
        </div>
      ))}

      {TOPICS.map((topic, topicIndex) => (
        <div
          key={`${topic.id}-label`}
          className={cn('product-hero-rise absolute z-[2] flex justify-center', topic.labelClassName)}
          style={riseStyle(250 + topicIndex * 160)}
        >
          <span className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 shadow-sm dark:bg-card sm:ps-1.5">
            <span className="hidden size-5 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground sm:flex">
              <Hash className="size-3" aria-hidden />
            </span>
            <span dir="ltr" className="truncate font-mono text-[11px] text-foreground">
              {topic.id}
            </span>
            <span className="hidden h-3 w-px bg-border sm:block" aria-hidden />
            <span className="hidden items-center gap-1 text-[10px] text-muted-foreground sm:inline-flex">
              <Users className="size-3" aria-hidden />
              <span dir="ltr">{topic.subscribers}</span>
            </span>
          </span>
        </div>
      ))}

      <div
        className="product-hero-rise absolute left-1/2 top-[38%] z-[1] -translate-x-1/2 sm:top-[43%]"
        style={riseStyle(900)}
        aria-hidden
      >
        <span className="product-tone-shadow flex size-10 items-center justify-center rounded-xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] dark:bg-card">
          <Megaphone className="size-4" />
        </span>
      </div>

      <ArtChip className="bottom-[10%] end-0 hidden w-[150px] sm:block" delayMs={1100} floatDelayMs={500}>
        <div className="flex items-center justify-between gap-2">
          <p className="text-[11px] font-medium text-foreground">{t('Subscriber audiences')}</p>
          <ArtIconBadge icon={Users} tone="secondary" className="size-6" />
        </div>
        <div className="mt-1.5 flex flex-wrap gap-1">
          {TOPICS.map((topic) => (
            <span key={topic.id} className="rounded bg-muted/60 px-1.5 py-0.5 text-[10px] text-muted-foreground">
              {t(topic.useCase)}
            </span>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="bottom-[16%] start-0 hidden sm:block" delayMs={1300} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <span className="flex size-5 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
            <Plus className="size-3" strokeWidth={2.5} aria-hidden />
          </span>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            paige@acme.io
          </span>
        </div>
      </ArtChip>
    </div>
  )
}

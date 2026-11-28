import {
  MessageSquare,
  PauseCircle,
  Webhook,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtIconBadge,
  ArtPanel,
  ArtToken,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** One alert's life as project events: it opens, someone acknowledges it, it resolves. */
const EVENTS = [
  { time: '09:23:04', action: 'create', state: 'firing' },
  { time: '09:41:17', action: 'update', state: 'acknowledged' },
  { time: '10:02:51', action: 'update', state: 'resolved' },
] as const

const TEMPLATES: { icon: LucideIcon; title: string; description: string }[] = [
  {
    icon: MessageSquare,
    title: 'Alerts to chat',
    description:
      'Post alerts to Slack, Discord, Microsoft Teams, or Google Chat.',
  },
  {
    icon: PauseCircle,
    title: 'Pause on usage spike',
    description: 'Turn off a function or site when its monitor fires.',
  },
]

/** Alert events, where they go, and the function templates that act on them. */
export function MonitorsEventsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(40)}
        title={
          <span dir="ltr" className="font-mono">
            {t('Project events')}
          </span>
        }
        bodyClassName="p-0"
      >
        <ul
          dir="ltr"
          className="divide-y divide-border/70 font-mono text-[10.5px]"
        >
          {EVENTS.map((event, index) => (
            <li
              key={event.time}
              className="product-hero-rise grid grid-cols-[4.25rem_minmax(0,1fr)_auto] items-center gap-2 px-4 py-2"
              style={riseStyle(200 + index * 140)}
            >
              <span className="text-muted-foreground">{event.time}</span>
              <span className="min-w-0 truncate text-foreground">
                monitors.egress-spike.alerts.6f2a9c.
                <span className="text-[var(--tone-ink)]">{event.action}</span>
              </span>
              <span
                className={cn(
                  'rounded px-1.5 py-px text-[9.5px]',
                  event.state === 'firing' &&
                    'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                  event.state === 'acknowledged' &&
                    'bg-amber-500/10 text-amber-700 dark:text-amber-300',
                  event.state === 'resolved' &&
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                )}
              >
                {event.state}
              </span>
            </li>
          ))}
        </ul>
        <pre
          dir="ltr"
          className="overflow-x-auto border-t border-border bg-muted/20 px-4 py-3 font-mono text-[10.5px] leading-[17px]"
        >
          <ArtToken tone="punctuation">{'{'}</ArtToken>
          {'\n  '}
          <ArtToken tone="property">"status"</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="string">"firing"</ArtToken>
          <ArtToken tone="punctuation">,</ArtToken>
          {'\n  '}
          <ArtToken tone="property">"severity"</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="string">"critical"</ArtToken>
          <ArtToken tone="punctuation">,</ArtToken>
          {'\n  '}
          <ArtToken tone="property">"metric"</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="string">"network.outbound"</ArtToken>
          <ArtToken tone="punctuation">,</ArtToken>
          {'\n  '}
          <ArtToken tone="property">"value"</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="number">6200000000</ArtToken>
          <ArtToken tone="punctuation">,</ArtToken>
          {'\n  '}
          <ArtToken tone="property">"threshold"</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="number">5000000000</ArtToken>
          {'\n'}
          <ArtToken tone="punctuation">{'}'}</ArtToken>
        </pre>
      </ArtWindow>

      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        <ArtPanel
          delayMs={600}
          innerClassName="flex items-center gap-2.5 px-3 py-2.5"
        >
          <ArtIconBadge icon={Webhook} tone="neutral" />
          <span className="min-w-0">
            <span className="block text-[11.5px] font-semibold text-foreground">
              {t('Webhook')}
            </span>
            <span
              dir="ltr"
              className="block truncate font-mono text-[10px] text-muted-foreground"
            >
              POST hooks.example.com · 200
            </span>
          </span>
        </ArtPanel>
        <ArtPanel
          delayMs={720}
          innerClassName="flex items-center gap-2.5 px-3 py-2.5"
        >
          <ArtIconBadge icon={Zap} tone="primary" />
          <span className="min-w-0">
            <span className="block text-[11.5px] font-semibold text-foreground">
              {t('Function')}
            </span>
            <span
              dir="ltr"
              className="block truncate font-mono text-[10px] text-muted-foreground"
            >
              alerts-to-chat · completed
            </span>
          </span>
        </ArtPanel>
      </div>

      <div className="mt-2 grid gap-2 sm:grid-cols-2">
        {TEMPLATES.map((template, index) => {
          const Icon = template.icon
          return (
            <ArtPanel
              key={template.title}
              delayMs={860 + index * 120}
              innerClassName="h-full border-dashed px-3 py-2.5"
            >
              <p className="flex items-center gap-1.5 text-[11.5px] font-semibold text-foreground">
                <Icon className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
                {t(template.title)}
              </p>
              <p className="mt-1 text-[10.5px] leading-[15px] text-muted-foreground">
                {t(template.description)}
              </p>
              <p className="mt-1.5 text-[9.5px] font-medium uppercase tracking-wider text-muted-foreground">
                {t('Function template')}
              </p>
            </ArtPanel>
          )
        })}
      </div>
    </div>
  )
}

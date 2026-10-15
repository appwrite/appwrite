import { EyeOff } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtConnector,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const EXECUTIONS = [
  { id: '68a4e2f91b0c', status: 'completed', trigger: 'event', method: 'POST', path: '/', duration: '342ms' },
  { id: '68a4d8c03f21', status: 'completed', trigger: 'schedule', method: 'POST', path: '/', duration: '1.2s' },
  { id: '68a4c1aa7e55', status: 'completed', trigger: 'http', method: 'POST', path: '/webhook', duration: '89ms', selected: true },
  { id: '68a4b9024d18', status: 'failed', trigger: 'event', method: 'POST', path: '/', duration: '410ms' },
  { id: '68a4a11fc873', status: 'completed', trigger: 'http', method: 'GET', path: '/health', duration: '12ms' },
] as const

const LOG_LINES = [
  { level: 'log', text: 'Received checkout.session.completed' },
  { level: 'log', text: 'Processing order ord_8f2a91c' },
  { level: 'log', text: 'Updated inventory for 3 SKUs' },
  { level: 'log', text: 'Sent receipt via Messaging topic "receipts"' },
] as const

type Execution = (typeof EXECUTIONS)[number]

function triggerLabel(trigger: Execution['trigger']) {
  if (trigger === 'event') return 'Event'
  if (trigger === 'schedule') return 'Schedule'
  return 'HTTP'
}

function ExecutionRow({ execution, index }: { execution: Execution; index: number }) {
  const t = useT()
  const selected = 'selected' in execution && execution.selected
  const failed = execution.status === 'failed'

  return (
    <ArtPanel
      className={cn(selected ? 'lg:me-0' : 'lg:me-8', index % 2 === 1 && !selected && 'lg:ms-4')}
      innerClassName={cn(
        'flex items-center gap-3 px-3 py-2.5',
        selected &&
          'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
      )}
      delayMs={120 + index * 110}
    >
      <span
        className={cn(
          'size-2 shrink-0 rounded-full',
          failed ? 'bg-red-500' : 'bg-emerald-500',
        )}
        aria-hidden
      />
      <span dir="ltr" className="w-[6.5rem] shrink-0 truncate text-start font-mono text-[11px] text-foreground">
        {execution.id}
      </span>
      <Badge variant="outline" className="hidden text-[10px] sm:inline-flex">
        {t(triggerLabel(execution.trigger))}
      </Badge>
      <span dir="ltr" className="min-w-0 flex-1 truncate text-start font-mono text-[11px] text-muted-foreground">
        {execution.method} {execution.path}
      </span>
      <span dir="ltr" className="shrink-0 font-mono text-[11px] tabular-nums text-foreground">
        {execution.duration}
      </span>
    </ArtPanel>
  )
}

function ExecutionDetails() {
  const t = useT()
  const selected = EXECUTIONS.find((execution) => 'selected' in execution && execution.selected) ?? EXECUTIONS[0]

  return (
    <ArtPanel innerClassName="product-tone-shadow p-4" delayMs={500}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Execution details')}
          </p>
          <p dir="ltr" className="mt-0.5 truncate text-start font-mono text-[12px] font-medium text-foreground">
            {selected.id}
          </p>
        </div>
        <div className="flex items-center gap-1.5">
          <Badge variant="completed" className="text-[10px]">
            {t('Completed')}
          </Badge>
          <Badge variant="success" className="font-mono text-[10px]">
            200
          </Badge>
        </div>
      </div>

      <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[11px] sm:grid-cols-4">
        <div>
          <dt className="text-muted-foreground">{t('Trigger')}</dt>
          <dd className="mt-0.5 font-medium text-foreground">{t(triggerLabel(selected.trigger))}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">{t('Method')}</dt>
          <dd dir="ltr" className="mt-0.5 text-start font-mono text-foreground">{selected.method}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">{t('Path')}</dt>
          <dd dir="ltr" className="mt-0.5 truncate text-start font-mono text-foreground">{selected.path}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">{t('Duration')}</dt>
          <dd dir="ltr" className="mt-0.5 text-start font-mono text-foreground">{selected.duration}</dd>
        </div>
      </dl>

      <div className="mt-4 flex gap-4 border-b border-border text-[11px]">
        {(['Logs', 'Errors', 'Headers', 'Body'] as const).map((tab) => (
          <span
            key={tab}
            className={cn(
              '-mb-px border-b-2 pb-2',
              tab === 'Logs'
                ? 'border-[var(--tone-ink)] font-medium text-foreground'
                : 'border-transparent text-muted-foreground',
            )}
          >
            {t(tab)}
          </span>
        ))}
      </div>

      <ul dir="ltr" className="mt-3 space-y-1 font-mono text-[10.5px] leading-relaxed">
        {LOG_LINES.map((line, index) => (
          <li
            key={line.text}
            className="product-hero-rise flex gap-2 text-start"
            style={riseStyle(900 + index * 220)}
          >
            <span className="shrink-0 text-[var(--tone-ink)]">{line.level}</span>
            <span className="min-w-0 truncate text-foreground">{line.text}</span>
          </li>
        ))}
      </ul>
    </ArtPanel>
  )
}

export function FunctionsExecutionsVisual() {
  const t = useT()

  return (
    <div className="mx-auto grid max-w-6xl items-center gap-8 lg:grid-cols-[minmax(0,1fr)_56px_minmax(0,0.95fr)] lg:gap-0">
      <div className="min-w-0">
        <p
          className="product-hero-rise mb-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
          style={riseStyle(0)}
        >
          {t('Executions')}
        </p>
        <div className="space-y-2">
          {EXECUTIONS.map((execution, index) => (
            <ExecutionRow key={execution.id} execution={execution} index={index} />
          ))}
        </div>
      </div>

      <div className="hidden items-center pt-7 lg:flex">
        <ArtConnector travel travelDelayMs={600} />
      </div>

      <div className="min-w-0 space-y-3">
        <ExecutionDetails />
        <ArtPanel
          className="w-fit max-w-full lg:ms-auto lg:me-6"
          innerClassName="flex items-center gap-2 rounded-full px-3 py-1.5"
          delayMs={1300}
          float
          floatDelayMs={500}
        >
          <EyeOff className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <span className="text-[11px] text-muted-foreground">
            {t('Response bodies are not stored by default.')}
          </span>
        </ArtPanel>
      </div>
    </div>
  )
}

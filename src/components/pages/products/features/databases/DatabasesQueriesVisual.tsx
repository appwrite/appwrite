import { ArrowRightLeft, Check, Filter, GitBranch, Layers } from 'lucide-react'
import type { ReactNode } from 'react'
import {
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  ArtToken as T,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const QUERY_PILLS: { id: string; code: ReactNode }[] = [
  {
    id: 'status',
    code: (
      <>
        <T tone="class">Query</T>
        <T tone="punctuation">.</T>
        <T tone="function">equal</T>
        <T tone="punctuation">(</T>
        <T tone="string">&apos;status&apos;</T>
        <T tone="punctuation">, </T>
        <T tone="string">&apos;active&apos;</T>
        <T tone="punctuation">)</T>
      </>
    ),
  },
  {
    id: 'team',
    code: (
      <>
        <T tone="class">Query</T>
        <T tone="punctuation">.</T>
        <T tone="function">equal</T>
        <T tone="punctuation">(</T>
        <T tone="string">&apos;teamId&apos;</T>
        <T tone="punctuation">, </T>
        <T tone="string">&apos;acme&apos;</T>
        <T tone="punctuation">)</T>
      </>
    ),
  },
  {
    id: 'order',
    code: (
      <>
        <T tone="class">Query</T>
        <T tone="punctuation">.</T>
        <T tone="function">orderDesc</T>
        <T tone="punctuation">(</T>
        <T tone="string">&apos;$createdAt&apos;</T>
        <T tone="punctuation">)</T>
      </>
    ),
  },
  {
    id: 'limit',
    code: (
      <>
        <T tone="class">Query</T>
        <T tone="punctuation">.</T>
        <T tone="function">limit</T>
        <T tone="punctuation">(</T>
        <T tone="number">3</T>
        <T tone="punctuation">)</T>
      </>
    ),
  },
]

const RESULT_ROWS = [
  { id: 'row_01', title: 'Launch checklist', offset: 'sm:ms-0' },
  { id: 'row_02', title: 'Billing migration', offset: 'sm:ms-4' },
  { id: 'row_03', title: 'Onboarding emails', offset: 'sm:ms-8' },
] as const

const TRANSACTION_STEPS = [
  { method: 'createRow', table: 'orders' },
  { method: 'updateRow', table: 'teams' },
  { method: 'deleteRow', table: 'drafts' },
] as const

export function DatabasesQueriesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-4">
      <div className="flex flex-wrap items-center justify-center gap-1.5">
        <span
          className="product-hero-rise inline-flex items-center gap-1.5 rounded-full bg-[rgb(var(--tone-rgb)/0.14)] px-2.5 py-1 text-[11px] font-medium text-[var(--tone-ink)]"
          style={riseStyle(60)}
        >
          <Filter className="size-3" aria-hidden />
          {t('2 filters')}
        </span>
        {QUERY_PILLS.map((pill, index) => (
          <span
            key={pill.id}
            dir="ltr"
            className={cn(
              'product-hero-rise rounded-full border border-border bg-background px-2.5 py-1 font-mono text-[10.5px] shadow-sm dark:bg-card',
              index > 1 && 'hidden sm:inline-block',
            )}
            style={riseStyle(160 + index * 110)}
          >
            {pill.code}
          </span>
        ))}
      </div>

      <div className="mx-auto h-8 w-0 border-s border-dashed border-foreground/25" aria-hidden />

      <div className="grid items-center gap-4 sm:grid-cols-[minmax(0,1fr)_36px_170px] sm:gap-0">
        <div className="space-y-2">
          {RESULT_ROWS.map((row, index) => (
            <ArtPanel
              key={row.id}
              className={cn('sm:max-w-[290px]', row.offset)}
              innerClassName={cn(
                'flex items-center gap-3 px-3 py-2',
                index === 0 && 'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
              )}
              delayMs={500 + index * 130}
              float
              floatDelayMs={index * 450}
            >
              <div className="min-w-0 flex-1">
                <p className="truncate text-[12px] font-medium text-foreground">{row.title}</p>
                <p dir="ltr" className="truncate text-start font-mono text-[10px] text-muted-foreground">
                  {row.id} · teamId: acme
                </p>
              </div>
              <span className="shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] font-medium text-emerald-700 bg-emerald-500/10 dark:text-emerald-400">
                active
              </span>
            </ArtPanel>
          ))}
        </div>

        <div className="hidden self-start pt-6 sm:block">
          <ArtConnector travel travelDelayMs={600} />
        </div>

        <ArtPanel className="sm:self-start" innerClassName="px-3 py-2.5" delayMs={900} float floatDelayMs={900}>
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={GitBranch} tone="secondary" />
            <p className="text-[12px] font-medium text-foreground">{t('Relationships')}</p>
          </div>
          <div className="mt-2 rounded-md border border-border bg-muted/20 px-2 py-1.5">
            <p dir="ltr" className="text-start font-mono text-[10px] text-muted-foreground">
              teams/acme
            </p>
            <p className="text-[11px] font-medium text-foreground">Acme Engineering</p>
          </div>
          <p className="mt-2 text-[10px] leading-4 text-muted-foreground">
            {t('Link related tables without custom joins.')}
          </p>
        </ArtPanel>
      </div>

      <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <ArtPanel className="sm:w-[290px]" innerClassName="product-tone-shadow px-3.5 py-3" delayMs={1100}>
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={ArrowRightLeft} />
            <p className="text-[12px] font-semibold text-foreground">{t('Transaction')}</p>
            <span className="ms-auto inline-flex items-center gap-1 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
              <Check className="size-3" strokeWidth={3} aria-hidden />
              {t('Completed')}
            </span>
          </div>
          <ol dir="ltr" className="mt-2.5 space-y-1">
            {TRANSACTION_STEPS.map((step, index) => (
              <li
                key={step.method}
                className="product-hero-rise flex items-center gap-2 font-mono text-[10.5px]"
                style={riseStyle(1300 + index * 140)}
              >
                <span className="flex size-3.5 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                  <Check className="size-2" strokeWidth={3} aria-hidden />
                </span>
                <T tone="function">{step.method}</T>
                <T tone="punctuation">(</T>
                <T tone="string">&apos;{step.table}&apos;</T>
                <T tone="punctuation">)</T>
              </li>
            ))}
          </ol>
          <p className="mt-2.5 text-[10px] text-muted-foreground">{t('Commit multi-step writes atomically.')}</p>
        </ArtPanel>

        <ArtPanel
          className="hidden sm:mb-6 sm:block"
          innerClassName="flex items-center gap-2 rounded-lg px-2.5 py-2"
          delayMs={1500}
          float
          floatDelayMs={500}
        >
          <ArtIconBadge icon={Layers} tone="neutral" />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Bulk operations')}</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              upsertRows · 1,000
            </p>
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}

import { Braces, Info, Layers, Table as TableIcon } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { APPWRITE_DATABASE_MODELS, COMPETITOR_DATABASE_MODEL } from '@/lib/alternatives/database-models'
import type { DatabaseCompute, DatabaseModelId } from '@/lib/alternatives/database-models'
import { ALTERNATIVE_REGISTRY } from '@/lib/alternatives/registry'
import type { AlternativeId } from '@/lib/alternatives/types'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { AppwriteMark, ComparisonHeading, ComparisonSection, CompetitorMonogram } from './ComparisonParts'

const MODEL_ICONS: Record<DatabaseModelId, LucideIcon | typeof PostgresElephantIcon> = {
  tablesdb: TableIcon,
  documentsdb: Braces,
  vectorsdb: Layers,
  postgresql: PostgresElephantIcon,
  mysql: MySQLDolphinIcon,
}

const COMPUTE_LABEL: Record<DatabaseCompute, string> = {
  serverless: 'Serverless',
  dedicated: 'Dedicated',
}

/**
 * Five Appwrite database models in open columns. Under each one sits the other platform's
 * slot: lit where it has that model, dashed where it does not.
 */
export function DatabaseModelsSection({
  id,
  title,
  description,
}: {
  id: AlternativeId
  title: string
  description: string
}) {
  const t = useT()
  const meta = ALTERNATIVE_REGISTRY[id]
  const competitor = COMPETITOR_DATABASE_MODEL[id]
  if (!competitor) return null

  return (
    <ComparisonSection
      backdrop={
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70" />
          <div className="product-tone-glow absolute -start-[20%] top-1/3 h-[640px] w-[960px]" />
        </div>
      }
    >
      <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:gap-16">
        <ComparisonHeading eyebrow="Databases" title={title} description={description} />
        <dl className="flex flex-wrap gap-x-12 gap-y-6">
          <div className="flex flex-col-reverse gap-2">
            <dt className="max-w-[11rem] text-[13px] leading-5 text-muted-foreground">
              {t('Database models in one Appwrite project')}
            </dt>
            <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-[var(--tone-ink)] tabular-nums sm:text-[52px] lg:text-[64px]">
              {APPWRITE_DATABASE_MODELS.length}
            </dd>
          </div>
          <div className="flex flex-col-reverse gap-2">
            <dt className="flex max-w-[11rem] items-start gap-2 text-[13px] leading-5 text-muted-foreground">
              <CompetitorMonogram name={meta.name} className="mt-0.5 shrink-0" />
              <span className="min-w-0 break-words">
                {meta.name}: {t(competitor.engine)}
              </span>
            </dt>
            <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-foreground/40 tabular-nums sm:text-[52px] lg:text-[64px]">
              1
            </dd>
          </div>
        </dl>
      </div>

      <div className="mt-14 flex flex-wrap items-center gap-x-6 gap-y-2 text-[12px] text-muted-foreground">
        <span className="flex items-center gap-2 text-foreground">
          <AppwriteMark className="size-3.5" />
          Appwrite
        </span>
        <span className="flex items-center gap-2">
          <CompetitorMonogram name={meta.name} className="size-4 text-[9px]" />
          {meta.name}
        </span>
      </div>

      <ul className="mt-6 grid grid-cols-2 gap-x-4 gap-y-12 sm:grid-cols-3 lg:grid-cols-5 lg:gap-x-6">
        {APPWRITE_DATABASE_MODELS.map((model, index) => {
          const Icon = MODEL_ICONS[model.id]
          const isSlot = competitor.slot === model.id
          const extra = competitor.extra?.slot === model.id ? competitor.extra : null
          return (
            <li key={model.id} className="product-hero-rise flex flex-col" style={riseStyle(120 + index * 90)}>
              <span className="relative flex size-12">
                <span
                  className="absolute -inset-3 rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.3),transparent_70%)]"
                  aria-hidden
                />
                <span className="relative flex size-12 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background text-[var(--tone-ink)] shadow-[0_12px_30px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
                  <Icon className="size-5" aria-hidden />
                </span>
              </span>
              <p className="mt-4 font-aeonik-pro text-[18px] tracking-tight text-foreground">{model.name}</p>
              <p className="mt-1 text-[12px] text-muted-foreground">{t(model.model)}</p>
              <p className="mt-3 flex flex-wrap gap-1.5">
                {model.compute.map((compute) => (
                  <span
                    key={compute}
                    className="rounded-full border border-[rgb(var(--tone-rgb)/0.35)] bg-[rgb(var(--tone-rgb)/0.08)] px-2 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]"
                  >
                    {t(COMPUTE_LABEL[compute])}
                  </span>
                ))}
              </p>

              <div
                className={cn(
                  'mt-6 flex min-h-[92px] flex-1 flex-col justify-center rounded-xl border px-3 py-3',
                  isSlot
                    ? 'border-foreground/15 bg-muted/50'
                    : extra
                      ? 'border-foreground/10 bg-[repeating-linear-gradient(135deg,transparent_0_5px,color-mix(in_srgb,var(--foreground)_6%,transparent)_5px_10px)]'
                      : 'border-dashed border-foreground/15',
                )}
              >
                {isSlot ? (
                  <>
                    <p className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                      <CompetitorMonogram name={meta.name} className="size-4 text-[9px]" />
                      {meta.name}
                    </p>
                    <p className="mt-1.5 text-[13px] font-medium text-foreground/80">{t(competitor.engine)}</p>
                    <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">{t(competitor.compute)}</p>
                  </>
                ) : extra ? (
                  <>
                    <p className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                      <CompetitorMonogram name={meta.name} className="size-4 text-[9px]" />
                      {meta.name}
                    </p>
                    <p className="mt-1.5 text-[12px] leading-4 text-muted-foreground">{t(extra.label)}</p>
                  </>
                ) : (
                  <p className="text-center font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground/60">
                    <span className="sr-only">{meta.name}: </span>
                    {t('Not offered')}
                  </p>
                )}
              </div>
            </li>
          )
        })}
      </ul>

      <p className="mt-8 flex max-w-3xl items-start gap-2.5 text-[13px] leading-6 text-muted-foreground">
        <Info className="mt-1 size-4 shrink-0" strokeWidth={1.75} aria-hidden />
        {t(competitor.note)}
      </p>
    </ComparisonSection>
  )
}

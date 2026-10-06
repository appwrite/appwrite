import { ArrowLeftRight, ScanSearch, Server, Wrench } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { COMPETITOR_SOURCE } from '@/lib/alternatives/platform'
import { ALTERNATIVE_REGISTRY } from '@/lib/alternatives/registry'
import type { AlternativeId } from '@/lib/alternatives/types'
import { useT } from '@/lib/i18n/translate'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { ComparisonHeading, ComparisonSection, CompetitorMonogram } from './ComparisonParts'

/** Abbreviated on purpose; the full command with volumes lives in the self-hosting docs. */
const SELF_HOST_COMMAND = 'docker run -it --rm … --entrypoint="install" appwrite/appwrite:2.3.0'

const BENEFITS: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: 'No lock-in',
    description: 'Move between Appwrite Cloud and your own servers by changing one endpoint.',
    icon: ArrowLeftRight,
  },
  {
    title: 'Run it anywhere',
    description: 'Self-host on any cloud or on premises for data residency and compliance.',
    icon: Server,
  },
  {
    title: 'Nothing hidden',
    description: 'Read the code that handles your users and data, and audit how it works.',
    icon: ScanSearch,
  },
  {
    title: 'Fix it, extend it',
    description: 'Patch, extend, or contribute back instead of waiting on a vendor roadmap.',
    icon: Wrench,
  },
]

/** Why open source matters, next to how the other platform ships. Open layout: type, icons, and hairlines. */
export function OpenSourceSection({
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
  const source = COMPETITOR_SOURCE[id]
  const github = MARKETING_SOCIAL_STATS.github

  const stats = [
    { value: github.stat, label: 'GitHub stars' },
    { value: github.contributors, label: 'Contributors' },
  ]

  return (
    <ComparisonSection
      backdrop={
        <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
          <div className="product-tone2-glow absolute -start-[25%] top-1/2 h-[640px] w-[940px] -translate-y-1/2" />
        </div>
      }
    >
      <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)] lg:gap-20">
        <div>
          <ComparisonHeading eyebrow="Open source" title={title} description={description} />

          <dl className="mt-10 flex flex-wrap gap-x-12 gap-y-6">
            {stats.map((stat, index) => (
              <div
                key={stat.label}
                className="product-hero-rise flex flex-col-reverse gap-2"
                style={riseStyle(80 + index * 90)}
              >
                <dt className="text-[13px] text-muted-foreground">{t(stat.label)}</dt>
                <dd className="font-aeonik-pro text-[40px] leading-none tracking-tight text-foreground tabular-nums sm:text-[52px]">
                  <bdi>{stat.value}</bdi>
                </dd>
              </div>
            ))}
          </dl>

          <div className="product-hero-rise mt-8" style={riseStyle(260)}>
            <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground">
              {t('Self-host in one command')}
            </p>
            <p
              dir="ltr"
              className="mt-2.5 flex max-w-md border-s-2 border-[var(--tone-ink)] py-1 ps-3 font-mono text-[12px] leading-5 text-foreground/85"
            >
              <span className="me-2 select-none text-[var(--tone-ink)]">$</span>
              <span className="[overflow-wrap:anywhere]">{SELF_HOST_COMMAND}</span>
            </p>
          </div>
        </div>

        <div className="lg:pt-3">
          <ul className="grid gap-x-10 gap-y-10 sm:grid-cols-2">
            {BENEFITS.map((benefit, index) => {
              const Icon = benefit.icon
              return (
                <li
                  key={benefit.title}
                  className="product-hero-rise relative border-t border-foreground/15 pt-5"
                  style={riseStyle(160 + index * 90)}
                >
                  <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
                  <h3 className="flex items-center gap-2.5 text-[16px] font-medium text-foreground">
                    <Icon className="size-[18px] shrink-0 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
                    {t(benefit.title)}
                  </h3>
                  <p className="mt-2 text-[13px] leading-6 text-muted-foreground">{t(benefit.description)}</p>
                </li>
              )
            })}
          </ul>

          <div
            className="product-hero-rise mt-12 flex items-start gap-3 border-t border-dashed border-foreground/20 pt-6"
            style={riseStyle(560)}
          >
            <CompetitorMonogram name={meta.name} className="mt-0.5" />
            <div className="min-w-0">
              <p className="text-[14px] font-medium text-foreground/80">
                {meta.name} <span className="px-1 text-muted-foreground">·</span> {t(source.status)}
              </p>
              <p className="mt-1 text-[13px] leading-6 text-muted-foreground">{t(source.detail)}</p>
            </div>
          </div>
        </div>
      </div>
    </ComparisonSection>
  )
}

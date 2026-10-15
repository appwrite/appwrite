import { COMPANY_SECTION_IDS } from '@/lib/company/sections'
import { scrollToCompanySection } from '@/lib/company/section-scroll'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  companyTeamIntro,
  companyTeamLinks,
  companyTeamMetrics,
  companyTeamPhotos,
  companyTeamPillars,
  companyTeamCta,
  companyTeamProductFirst,
  companyTeamRoleSegments,
  companyTeamValues,
  type CompanyTeamMetric,
  type CompanyTeamPhoto,
} from '@/lib/company/team'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { ArrowUpRight } from 'lucide-react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'

function TeamMetricCell({ value, label }: CompanyTeamMetric) {
  const t = useT()
  return (
    <div className="flex flex-col items-center justify-center px-4 py-8 text-center sm:px-6 sm:py-10">
      <p className="font-aeonik-pro text-[28px] font-normal leading-none tracking-tight text-foreground tabular-nums sm:text-[32px]">
        {value}
      </p>
      <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-[11px]">
        {t(label)}
      </p>
    </div>
  )
}

function TeamSnapshotOverview() {
  const t = useT()
  return (
    <div className="mx-auto max-w-4xl overflow-hidden rounded-xl border border-border bg-card/50">
      <ul className="grid grid-cols-1 divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        {companyTeamMetrics.map((metric) => (
          <li key={metric.id} className="min-w-0">
            <TeamMetricCell {...metric} />
          </li>
        ))}
      </ul>

      <div className="border-t border-border bg-muted/20 px-4 py-4 sm:px-6 sm:py-5">
        <p className="text-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-[11px]">
          {t('How we work')}
        </p>
        <ul className="mt-3 flex flex-wrap items-center justify-center gap-2">
          {companyTeamValues.map((value) => (
            <li key={value.id}>
              <span className="inline-flex rounded-full border border-border bg-background px-3 py-1.5 text-[12px] font-medium text-foreground sm:text-[13px]">
                {t(value.label)}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function TeamRoleBreakdown() {
  const t = useT()
  return (
    <div className="mx-auto max-w-2xl space-y-5 text-center">
      <div>
        <h3 className="text-[15px] font-semibold text-foreground">
          {t(companyTeamProductFirst.title)}
        </h3>
        <p className="mt-2 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
          {t(companyTeamProductFirst.description)}
        </p>
      </div>

      <div>
        <div
          className="flex h-2 overflow-hidden rounded-md bg-muted/30"
          role="img"
          aria-label={`${t('Team composition:')} ${companyTeamRoleSegments.map((segment) => `${t(segment.label)} ${segment.percentage}%`).join(', ')}`}
        >
          {companyTeamRoleSegments.map((segment) => (
            <div
              key={segment.id}
              className={cn(
                'h-full min-w-0 first:rounded-s-md last:rounded-e-md',
                segment.barClassName,
              )}
              style={{ width: `${segment.percentage}%` }}
              title={`${t(segment.label)}: ${segment.percentage}%`}
            />
          ))}
        </div>

        <ul className="mt-4 flex flex-wrap justify-center gap-x-5 gap-y-2">
          {companyTeamRoleSegments.map((segment) => (
            <li
              key={segment.id}
              className="flex min-w-0 items-center gap-2 text-[13px] text-muted-foreground"
            >
              <span
                className={cn('size-2 shrink-0 rounded-full', segment.barClassName)}
                aria-hidden
              />
              <span className="text-foreground">{t(segment.label)}</span>
              <span className="tabular-nums">{segment.percentage}%</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function TeamSnapshot() {
  return (
    <div className="mt-12 space-y-12 border-t border-border pt-12 sm:mt-14 sm:space-y-14 sm:pt-14">
      <TeamSnapshotOverview />
      <TeamRoleBreakdown />
    </div>
  )
}

function TeamPhoto({
  src,
  alt,
  caption,
  gridClassName,
  variant = 'standard',
}: CompanyTeamPhoto) {
  const t = useT()
  return (
    <figure className={cn('min-w-0', gridClassName)}>
      <div
        className={cn(
          'overflow-hidden rounded-lg bg-muted/20',
          variant === 'wide' ? 'aspect-[3/2]' : 'aspect-[4/3]',
        )}
      >
        <img
          src={src}
          alt={t(alt)}
          className="h-full w-full object-cover object-center"
          loading="lazy"
          decoding="async"
        />
      </div>
      <figcaption className="mt-2 text-[12px] leading-relaxed text-muted-foreground">
        {t(caption)}
      </figcaption>
    </figure>
  )
}

function TeamPhotoGrid() {
  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-12 lg:gap-5">
      {companyTeamPhotos.map((photo) => (
        <TeamPhoto key={photo.id} {...photo} />
      ))}
    </div>
  )
}

function TeamPillar({ title, body }: (typeof companyTeamPillars)[number]) {
  const t = useT()
  return (
    <div className="space-y-2.5 md:px-6 md:first:ps-0 md:last:pe-0">
      <h3 className="text-[15px] font-semibold text-foreground">{t(title)}</h3>
      <p className="text-[14px] leading-7 text-muted-foreground">{t(body)}</p>
    </div>
  )
}

function TeamCta() {
  const t = useT()
  return (
    <div className="mt-12 flex flex-col gap-4 border-t border-border pt-12 sm:mt-14 sm:pt-14 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="text-[14px] font-medium text-foreground">
          {t(companyTeamCta.title)}
        </p>
        <p className="mt-1 text-[13px] text-muted-foreground">
          {t(companyTeamCta.description)}
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap gap-3">
        <Button
          variant="brandCta"
          onClick={() => scrollToCompanySection(COMPANY_SECTION_IDS.careers)}
        >
          {t('View careers')}
        </Button>
        <Button variant="outline" asChild>
          <BlogPageAnchor
            href={companyTeamLinks.evolutionBlog}
            className="gap-1.5"
          >
            {t('How we hire')}
            <ArrowUpRight className="size-3.5" aria-hidden />
          </BlogPageAnchor>
        </Button>
      </div>
    </div>
  )
}

export function CompanyTeam() {
  const t = useT()
  return (
    <section
      id={COMPANY_SECTION_IDS.team}
      className="relative isolate scroll-mt-28 overflow-x-hidden border-b border-border"
    >
      <SectionSoftLight tone="teal" position="left" align="top" />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
        <div className="max-w-3xl">
          <h2 className="font-aeonik-pro text-balance text-[28px] font-normal leading-none tracking-tight text-foreground sm:text-[36px]">
            {t(companyTeamIntro.title)}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-5 text-[14px] leading-7 text-muted-foreground sm:mt-6 sm:text-[15px]">
            {t(companyTeamIntro.description)}
          </p>
        </div>

        <TeamSnapshot />

        <div className="mt-12 border-t border-border pt-12 sm:mt-14 sm:pt-14">
          <div className="grid gap-8 md:grid-cols-3 md:gap-0 md:divide-x md:divide-border">
            {companyTeamPillars.map((pillar) => (
              <TeamPillar key={pillar.id} {...pillar} />
            ))}
          </div>
        </div>

        <div className="mt-12 border-t border-border pt-12 sm:mt-14 sm:pt-14">
          <TeamPhotoGrid />
        </div>

        <TeamCta />
      </div>
    </section>
  )
}

import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  companyTeamFacts,
  companyTeamIntro,
  companyTeamLinks,
  companyTeamPhotos,
  companyTeamPillars,
  companyTeamCta,
  type CompanyTeamPhoto,
} from '@/lib/company/team'
import { cn } from '@/lib/utils'
import { ArrowUpRight } from 'lucide-react'

function TeamFactCell({ value, label }: (typeof companyTeamFacts)[number]) {
  return (
    <div className="px-5 py-5 sm:px-6 sm:py-6">
      <p className="font-aeonik-pro text-[28px] font-normal leading-none tracking-tight text-foreground tabular-nums sm:text-[32px]">
        {value}
      </p>
      <p className="mt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
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
  return (
    <figure
      className={cn(
        'group flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-border bg-card/50',
        gridClassName,
      )}
    >
      <div
        className={cn(
          'relative min-h-0 flex-1 overflow-hidden bg-muted/20',
          variant === 'wide' ? 'aspect-[2/1]' : 'aspect-[4/3]',
        )}
      >
        <img
          src={src}
          alt={alt}
          className="h-full w-full object-cover object-center transition-transform duration-500 group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
          loading="lazy"
          decoding="async"
        />
      </div>
      <figcaption className="shrink-0 border-t border-border px-4 py-3 text-[12px] leading-relaxed text-muted-foreground sm:px-5 sm:text-[13px]">
        {caption}
      </figcaption>
    </figure>
  )
}

function TeamPhotoGrid() {
  return (
    <div className="mt-12 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12 lg:gap-5 lg:auto-rows-fr">
      {companyTeamPhotos.map((photo) => (
        <TeamPhoto key={photo.id} {...photo} />
      ))}
    </div>
  )
}

function TeamPillar({ title, body }: (typeof companyTeamPillars)[number]) {
  return (
    <div className="space-y-3">
      <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
      <p className="text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
        {body}
      </p>
    </div>
  )
}

export function CompanyTeam() {
  return (
    <section className="relative isolate overflow-x-hidden border-b border-border">
      <SectionSoftLight tone="teal" position="left" />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
        <div className="max-w-3xl">
          <h2 className="font-aeonik-pro text-balance text-[28px] font-normal leading-none tracking-tight text-foreground sm:text-[36px]">
            {companyTeamIntro.title}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-4 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
            {companyTeamIntro.description}
          </p>
        </div>

        <div className="mt-10 overflow-hidden rounded-xl bg-border">
          <ul className="grid grid-cols-2 gap-px md:grid-cols-3 lg:grid-cols-6">
            {companyTeamFacts.map((fact) => (
              <li key={fact.id} className="min-w-0 bg-card/50">
                <TeamFactCell {...fact} />
              </li>
            ))}
          </ul>
        </div>

        <div className="mt-12 grid gap-10 md:grid-cols-3 md:gap-8 lg:gap-12">
          {companyTeamPillars.map((pillar) => (
            <TeamPillar key={pillar.id} {...pillar} />
          ))}
        </div>

        <TeamPhotoGrid />

        <div className="mt-12 flex flex-col gap-4 rounded-xl border border-border bg-muted/30 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div>
            <p className="text-[14px] font-medium text-foreground">
              {companyTeamCta.title}
            </p>
            <p className="mt-1 text-[13px] text-muted-foreground">
              {companyTeamCta.description}
            </p>
          </div>
          <div className="flex shrink-0 flex-wrap gap-3">
            <Button variant="brandCta" asChild>
              <a
                href={companyTeamLinks.careers}
                target="_blank"
                rel="noopener noreferrer"
              >
                View careers
              </a>
            </Button>
            <Button variant="outline" asChild>
              <a
                href={companyTeamLinks.evolutionBlog}
                target="_blank"
                rel="noopener noreferrer"
                className="gap-1.5"
              >
                How we hire
                <ArrowUpRight className="size-3.5" aria-hidden />
              </a>
            </Button>
          </div>
        </div>
      </div>
    </section>
  )
}

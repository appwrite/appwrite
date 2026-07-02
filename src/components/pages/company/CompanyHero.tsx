import { Button } from '@/components/ui/button'
import { companyHero } from '@/lib/company/hero'
import { useT } from '@/lib/i18n/translate'

function HeroColumn({ title, body }: { title: string; body: string }) {
  const t = useT()
  return (
    <div className="space-y-2.5">
      <h2 className="text-[15px] font-semibold text-foreground">{t(title)}</h2>
      <p className="text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
        {t(body)}
      </p>
    </div>
  )
}

export function CompanyHero() {
  const t = useT()
  return (
    <section className="relative border-b border-border">
      <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
        <div className="max-w-3xl">
          <h1 className="font-aeonik-pro text-balance text-[28px] font-normal leading-none tracking-tight text-foreground sm:text-[36px] lg:text-[40px]">
            {t(companyHero.title)}
            <span className="text-[var(--brand-cta)]">_</span>
          </h1>
          <p className="mt-5 text-[14px] leading-7 text-muted-foreground sm:mt-6 sm:text-[15px]">
            {t(companyHero.lead)}
          </p>
        </div>

        <div className="mt-10 grid gap-8 border-t border-border pt-10 md:grid-cols-2 md:gap-12 lg:gap-16">
          <HeroColumn
            title={companyHero.mission.title}
            body={companyHero.mission.body}
          />
          <HeroColumn
            title={companyHero.platform.title}
            body={companyHero.platform.body}
          />
        </div>

        <div className="mt-10 flex flex-col gap-4 border-t border-border pt-10 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-[14px] font-medium text-foreground sm:text-[15px]">
            {t(companyHero.tagline)}
          </p>
          <Button variant="brandCta" className="shrink-0" asChild>
            <a
              href={companyHero.careersUrl}
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('Join the team')}
            </a>
          </Button>
        </div>
      </div>
    </section>
  )
}

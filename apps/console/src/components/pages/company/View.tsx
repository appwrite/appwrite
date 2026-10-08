import { Github } from 'lucide-react'
import { CompanyFounder } from '@/components/pages/company/CompanyFounder'
import { CompanyHero } from '@/components/pages/company/CompanyHero'
import { CompanySectionNav } from '@/components/pages/company/CompanySectionNav'
import { CompanyTeam } from '@/components/pages/company/CompanyTeam'
import { CompanyTimeline } from '@/components/pages/company/CompanyTimeline'
import { HomeSoftLights, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import { COMPANY_SECTION_IDS } from '@/lib/company/sections'
import { companyOpenRoles } from '@/lib/company/open-roles'
import { useT } from '@/lib/i18n/translate'
import {
  angelInvestors,
  ventureInvestors,
} from '@/lib/company/investors'

function XIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      aria-hidden
      className={className}
      fill="currentColor"
    >
      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
    </svg>
  )
}

export function View() {
  const t = useT()
  return (
    <div className="relative overflow-x-hidden">
      <CompanyHero />

      <CompanySectionNav />

      <CompanyTimeline />

      <CompanyTeam />

      <CompanyFounder />

      <section
        id={COMPANY_SECTION_IDS.investors}
        className="relative isolate scroll-mt-28 overflow-x-hidden bg-muted/20"
      >
        <SectionSoftLight tone="purple" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <PricingSectionHeading
            title={t('Backed by top investors')}
            description={t(
              'Appwrite is proudly backed by some of the top investors in the industry.', // pragma: allowlist secret
            )}
            size="md"
          />

          <ul className="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {ventureInvestors.map((investor) => (
              <li key={investor.name}>
                <a
                  href={investor.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-24 items-center justify-center rounded-xl border border-border bg-card/50 p-4 transition-colors hover:bg-card"
                >
                  <img
                    src={investor.logoSrc}
                    alt={investor.name}
                    className="max-h-10 w-full max-w-[140px] object-contain dark:invert"
                  />
                </a>
              </li>
            ))}
          </ul>

          <h3 className="mt-14 text-center font-aeonik-pro text-[22px] font-normal text-foreground">
            {t('Angel Investors')}
          </h3>

          <ul className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {angelInvestors.map((investor) => (
              <li
                key={investor.name}
                className="flex flex-col rounded-xl border border-border bg-card/50 p-5"
              >
                <h4 className="text-[14px] font-semibold text-foreground">
                  {investor.name}
                </h4>
                <p className="mt-1 text-[13px] text-muted-foreground">{t(investor.role)}</p>
                {investor.organization ? (
                  <p className="text-[13px] text-muted-foreground">
                    {investor.organization}
                  </p>
                ) : null}
                {(investor.github || investor.twitter) && (
                  <div className="mt-auto flex gap-2 pt-4">
                    {investor.github ? (
                      <a
                        href={investor.github}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`${investor.name} ${t('on GitHub')}`}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                      >
                        <Github className="h-4 w-4" />
                      </a>
                    ) : null}
                    {investor.twitter ? (
                      <a
                        href={investor.twitter}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`${investor.name} ${t('on X')}`}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                      >
                        <XIcon className="h-3.5 w-3.5" />
                      </a>
                    ) : null}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section
        id={COMPANY_SECTION_IDS.careers}
        className="relative scroll-mt-28 border-t border-border"
      >
        <HomeSoftLights variant="testimonials" className="opacity-50" />
        <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <PricingSectionHeading
            title={t('Open roles')}
            description={t(
              'Find your next career at Appwrite and join a remote team building the platform developers and agents rely on.', // pragma: allowlist secret
            )}
            size="md"
          />

          {companyOpenRoles.length === 0 ? (
            <div className="mx-auto mt-10 max-w-xl rounded-xl border border-border bg-card/50 px-6 py-12 text-center">
              <p className="text-[14px] text-muted-foreground">
                {t('No open roles right now.')}
              </p>
            </div>
          ) : (
            <ul className="mx-auto mt-10 max-w-3xl divide-y divide-border overflow-hidden rounded-xl border border-border bg-card/50">
              {companyOpenRoles.map((role) => (
                <li key={role.id}>
                  <a
                    href={role.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex flex-col gap-1 px-5 py-4 transition-colors hover:bg-accent/40 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                  >
                    <div>
                      <p className="text-[14px] font-medium text-foreground">
                        {t(role.title)}
                      </p>
                      <p className="mt-0.5 text-[13px] text-muted-foreground">
                        {t(role.department)}
                      </p>
                    </div>
                    <p className="shrink-0 text-[13px] text-muted-foreground">
                      {t(role.location)}
                    </p>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </div>
  )
}

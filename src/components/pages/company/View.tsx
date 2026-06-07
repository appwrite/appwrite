import { Github } from 'lucide-react'
import { CompanyTeam } from '@/components/pages/company/CompanyTeam'
import { CompanyTimeline } from '@/components/pages/company/CompanyTimeline'
import { HomeSoftLights, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import { Button } from '@/components/ui/button'
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
  return (
    <div className="relative overflow-x-hidden">
      <HomeSoftLights variant="hero" />

      <section className="relative border-b border-border">
        <div className="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6 sm:py-20">
          <PricingSectionHeading
            as="h1"
            title="Unleashing creativity and innovation in every creator"
            description="Software development transforms our everyday lives, shaped by the creativity and innovation of developers and the AI agents they work with. At Appwrite, we enable them to build products the world loves by removing technical barriers with our backend platform."
          />
        </div>
      </section>

      <section className="relative border-b border-border">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-2 lg:gap-16 lg:items-start">
          <div className="space-y-6">
            <h2 className="font-aeonik-pro text-[28px] font-normal leading-tight tracking-tight text-foreground sm:text-[36px]">
              Designed for and by developers
              <span className="text-[var(--brand-cta)]">_</span>
            </h2>
            <Button variant="brandCta" asChild>
              <a
                href="https://appwrite.careers"
                target="_blank"
                rel="noopener noreferrer"
              >
                Join the team
              </a>
            </Button>
          </div>

          <div className="space-y-4 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
            <p>
              At Appwrite, our mission is to eliminate friction and abstract
              complexity for every creator. We build Appwrite for agents and
              developers, giving them the tools and experience they need to
              create and innovate without limits and with minimum concerns.
            </p>
            <p>
              We do this by building the most complete development platform,
              backed by the open source community. A platform with everything
              you need in one place, maximum flexibility, and minimum friction.
              From auth, databases, storage, and functions to MCP servers,
              Skills, and agent integrations, Appwrite moves with you on your
              journey from ideation to scale and helps you succeed in the
              challenges of today and those of tomorrow.
            </p>
            <p className="font-medium text-foreground">Build like a team of hundreds.</p>
          </div>
        </div>
      </section>

      <CompanyTeam />

      <CompanyTimeline />

      <section className="relative isolate overflow-x-hidden bg-muted/20">
        <SectionSoftLight tone="purple" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <PricingSectionHeading
            title="Backed by top investors"
            description="Appwrite is proudly backed by some of the top investors in the industry."
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
            Angel Investors
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
                <p className="mt-1 text-[13px] text-muted-foreground">{investor.role}</p>
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
                        aria-label={`${investor.name} on GitHub`}
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
                        aria-label={`${investor.name} on X`}
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

      <section className="relative border-t border-border">
        <HomeSoftLights variant="testimonials" className="opacity-50" />
        <div className="relative mx-auto max-w-xl px-4 py-16 text-center sm:px-6 sm:py-20">
          <h2 className="font-aeonik-pro text-[28px] font-normal leading-tight text-foreground sm:text-[36px]">
            Join the team
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-4 text-[14px] leading-7 text-muted-foreground">
            Find your next career at Appwrite and join a remote team building
            the platform developers and agents rely on.
          </p>
          <Button variant="outline" className="mt-6" asChild>
            <a
              href="https://appwrite.careers"
              target="_blank"
              rel="noopener noreferrer"
            >
              Careers
            </a>
          </Button>
        </div>
      </section>
    </div>
  )
}

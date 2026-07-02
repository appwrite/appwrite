import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import { companyFounder } from '@/lib/company/founder'
import { COMPANY_SECTION_IDS } from '@/lib/company/sections'
import { useT } from '@/lib/i18n/translate'

function SocialIconMask({
  icon,
  label,
}: {
  icon: string
  label: string
}) {
  return (
    <span
      className="h-4 w-4 bg-current"
      style={{
        WebkitMaskImage: `url(${icon})`,
        maskImage: `url(${icon})`,
        WebkitMaskSize: 'contain',
        maskSize: 'contain',
        WebkitMaskRepeat: 'no-repeat',
        maskRepeat: 'no-repeat',
        WebkitMaskPosition: 'center',
        maskPosition: 'center',
      }}
      role="img"
      aria-label={label}
    />
  )
}

const founderSocialLinks = [
  {
    id: 'website',
    href: companyFounder.links.website,
    label: `${companyFounder.name} website`,
    icon: '/icons/globe.svg',
  },
  {
    id: 'github',
    href: companyFounder.links.github,
    label: `${companyFounder.name} on GitHub`,
    icon: '/icons/github.svg',
  },
  {
    id: 'twitter',
    href: companyFounder.links.twitter,
    label: `${companyFounder.name} on X`,
    icon: '/icons/x.svg',
  },
  {
    id: 'linkedin',
    href: companyFounder.links.linkedin,
    label: `${companyFounder.name} on LinkedIn`,
    icon: '/icons/linkedin.svg',
  },
] as const

export function CompanyFounder() {
  const t = useT()
  return (
    <section
      id={COMPANY_SECTION_IDS.founder}
      className="relative isolate scroll-mt-28 overflow-x-hidden border-b border-border"
    >
      <SectionSoftLight tone="orange" position="right" align="center" />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16">
        <PricingSectionHeading title={t('Founder')} size="md" align="left" />

        <div className="mt-8 grid gap-8 lg:mt-10 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:items-start lg:gap-10 xl:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] xl:gap-12">
          <div className="flex flex-col items-center text-center lg:items-start lg:text-start">
            <img
              src={companyFounder.image.src}
              alt={companyFounder.image.alt}
              className="size-20 rounded-full object-cover object-center sm:size-24"
              loading="lazy"
              decoding="async"
            />
            <p className="mt-4 font-aeonik-pro text-[18px] font-normal leading-tight text-foreground sm:text-[20px]">
              {companyFounder.name}
            </p>
            <p className="mt-1 text-[13px] text-muted-foreground sm:text-[14px]">
              {t(companyFounder.title)}
            </p>
            <div className="mt-4 flex items-center justify-center gap-1 lg:justify-start">
              {founderSocialLinks.map((link) => (
                <a
                  key={link.id}
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={link.label}
                  className="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-muted/30 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  <SocialIconMask icon={link.icon} label={link.label} />
                </a>
              ))}
            </div>
          </div>

          <figure className="min-w-0 text-center lg:text-start">
            <div className="relative mx-auto w-fit max-w-2xl lg:mx-0">
              <span
                className="absolute end-full top-0.5 me-3 font-aeonik-pro text-[2rem] leading-none text-muted-foreground/30 sm:top-1 sm:me-4 sm:text-[2.25rem]"
                aria-hidden
              >
                &ldquo;
              </span>
              <blockquote className="text-center font-aeonik-pro text-balance text-[17px] font-normal leading-7 text-foreground sm:text-[18px] sm:leading-8 lg:text-start">
                {t(companyFounder.quote)}
              </blockquote>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-5 text-start md:grid-cols-2 md:gap-6 lg:mt-5">
              <div className="space-y-4">
                {companyFounder.bio.slice(0, 2).map((paragraph) => (
                  <p
                    key={paragraph}
                    className="text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7"
                  >
                    {t(paragraph)}
                  </p>
                ))}
              </div>
              <p className="text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7">
                {companyFounder.bio[2] ? t(companyFounder.bio[2]) : null}
              </p>
            </div>
          </figure>
        </div>
      </div>
    </section>
  )
}

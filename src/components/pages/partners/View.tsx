import { Check, ChevronRight, Handshake, Puzzle } from 'lucide-react'
import { HomeSoftLights, SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { MarketingApplicationForm } from '@/components/pages/marketing/MarketingApplicationForm'
import {
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingSectionHeading,
  MarketingStatGrid,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { CARD_LINK_HINT_CLASS } from '@/lib/link-styles'
import { cn } from '@/lib/utils'
import { submitPartnerApplication } from '@/lib/marketing/growth-forms'
import {
  PARTNERS_FORM_ID,
  partnerBenefits,
  partnerFormBullets,
  partnerStats,
  partnerTiers,
  partnerWays,
  partnerWhyAppwrite,
  partnersHero,
} from '@/lib/partners/content'

const PARTNER_FORM_FIELDS = [
  { name: 'name', label: 'Full name', type: 'text' as const, placeholder: "Walter O'Brien" },
  { name: 'email', label: 'Email address', type: 'email' as const, placeholder: 'walter@company.com' },
  { name: 'companyName', label: 'Company name', type: 'text' as const, placeholder: 'Acme Inc.' },
  { name: 'companyUrl', label: 'Company URL', type: 'url' as const, placeholder: 'https://', required: false },
  {
    name: 'message',
    label: "Any other details you'd like to share?",
    type: 'textarea' as const,
    placeholder: 'Your message...',
    colSpan: 2 as const,
  },
]

export function View() {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={partnersHero.eyebrow}
        title={partnersHero.title}
        description={partnersHero.description}
        align="left"
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <a href={`#${PARTNERS_FORM_ID}`}>Become a Partner</a>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <MarketingSiteLink href={partnersHero.catalogUrl}>
            Find a Partner
          </MarketingSiteLink>
        </Button>
      </MarketingHeroSection>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title="Growing together"
            description="Partner benefits designed to help you deliver more value to your clients."
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={partnerBenefits} columns={3} />
          </div>
        </div>
      </section>

      <section className="relative isolate overflow-hidden border-b border-border bg-muted/20">
        <SectionSoftLight tone="purple" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <div className={marketingSplitLayoutClassName({ align: 'center' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title="Partner Tiers"
                description="As your business grows, so do the opportunities with Appwrite. Our Partner Program is designed to evolve with you, offering flexible tiers that adapt to your unique needs and goals."
              />
              <Button variant="outline" className="mt-6" asChild>
                <a href={`#${PARTNERS_FORM_ID}`}>Become a Partner</a>
              </Button>
            </div>
            <div className="flex flex-col items-center gap-2">
              {partnerTiers.map((tier, index) => (
                <img
                  key={tier.title}
                  src={tier.badge}
                  alt={`${tier.title} Badge`}
                  className="max-w-[280px] object-contain"
                  style={{
                    marginBottom: index === partnerTiers.length - 1 ? 0 : `-${32 + index * 8}px`,
                    transform: `scale(${1 - index * 0.12})`,
                    zIndex: partnerTiers.length - index,
                  }}
                />
              ))}
            </div>
          </div>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading title="Ways to partner" size="md" />
          <div className="mt-10 grid gap-3 md:grid-cols-2">
            {partnerWays.map((way) => (
              <a
                key={way.title}
                href={way.href}
                target="_blank"
                rel="noopener noreferrer"
                className="group link-unstyled flex items-start gap-3 rounded-xl border border-border bg-card/50 p-5 transition-colors hover:bg-accent/50 sm:p-6"
              >
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                  {way.title === 'Experts' ? (
                    <Handshake className="size-5 text-[var(--brand-cta)]" aria-hidden />
                  ) : (
                    <Puzzle className="size-5 text-[var(--brand-cta)]" aria-hidden />
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-semibold text-foreground">{way.title}</p>
                  <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                    {way.description}
                  </p>
                  <span className={cn('mt-4 text-[13px]', CARD_LINK_HINT_CLASS)}>
                    {way.label}
                    <ChevronRight className="ms-0.5 size-4" aria-hidden />
                  </span>
                </div>
              </a>
            ))}
          </div>
        </div>
      </section>

      <section className="border-b border-border bg-background py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title="Partner with one of the fastest growing dev tool companies"
            description="Everyday thousands of companies are built on top of Appwrite. Benefit from our network as an Appwrite Partner."
            size="md"
          />
          <div className="mt-10">
            <MarketingStatGrid items={[...partnerStats]} compact={false} />
          </div>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading title="Why Appwrite?" size="md" />
          <div className="mt-10">
            <MarketingFeatureGrid items={partnerWhyAppwrite} columns={3} />
          </div>
        </div>
      </section>

      <section id={PARTNERS_FORM_ID} className="relative scroll-mt-28 border-b border-border">
        <HomeSoftLights variant="testimonials" className="opacity-40" />
        <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <div className={marketingSplitLayoutClassName({ align: 'start' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title="Become a Partner"
                description="Our team will review your application and follow up to ensure we're a perfect fit."
              />
              <ul className="mt-8 space-y-3">
                {partnerFormBullets.map((item) => (
                  <li key={item} className="flex items-center gap-2 text-[13px] text-foreground">
                    <Check className="size-4 shrink-0 text-[var(--brand-cta)]" aria-hidden />
                    {item}
                  </li>
                ))}
              </ul>
            </div>

            <div className="rounded-xl border border-border bg-card/50 p-6 sm:p-8">
              <MarketingApplicationForm
                fields={PARTNER_FORM_FIELDS}
                submitLabel="Submit application"
                successTitle="Thank you for applying"
                successDescription="Our team will review your application and follow up to ensure we're a perfect fit."
                onSubmit={async (values) => {
                  await submitPartnerApplication({
                    name: values.name ?? '',
                    email: values.email ?? '',
                    companyName: values.companyName ?? '',
                    companyUrl: values.companyUrl ?? '',
                    message: values.message ?? '',
                  })
                }}
              />
            </div>
          </div>
        </div>
      </section>
    </div>
  )
}

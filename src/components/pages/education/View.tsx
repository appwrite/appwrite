import { Link } from '@tanstack/react-router'
import { Github } from 'lucide-react'
import { EducationPartnerLogos } from '@/components/pages/education/EducationPartnerLogos'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { CommunitySupportChat } from '@/components/pages/marketing/CommunitySupportChat'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import {
  MarketingCtaSection,
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingSectionHeading,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { Badge } from '@/components/ui/badge'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  educationCommunity,
  educationCta,
  educationFaqItems,
  educationFeatureCards,
  educationHero,
  educationKickstart,
  educationSteps,
} from '@/lib/education/content'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={educationHero.eyebrow}
        title={educationHero.title}
        description={educationHero.description}
        gradientTitle
        leading={<EducationPartnerLogos />}
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/' }}
            {...analyticsAttrs('education-sign-up')}
          >
            {t('Sign up now')}
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <a
            href={educationHero.githubEducationUrl}
            target="_blank"
            rel="noopener noreferrer"
          >
            <Github className="me-1.5 h-4 w-4" />
            GitHub Education
          </a>
        </Button>
      </MarketingHeroSection>

      <section className="border-b border-border py-14 sm:py-16">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingFeatureGrid items={educationFeatureCards} columns={3} />
        </div>
      </section>

      <section className="relative border-b border-border bg-muted/20">
        <SectionSoftLight tone="teal" />
        <div className="relative z-[1] mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
          <div className={marketingSplitLayoutClassName({ align: 'center' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title={educationKickstart.title}
              />
              <div className="mt-5 space-y-4 text-[14px] leading-7 text-muted-foreground">
                {educationKickstart.paragraphs.map((paragraph) => (
                  <p key={paragraph.slice(0, 24)}>{t(paragraph)}</p>
                ))}
              </div>
            </div>
            <div className="overflow-hidden rounded-xl border border-border bg-card/50">
              <img
                src={educationKickstart.image}
                alt=""
                className="h-auto w-full object-cover"
                loading="lazy"
              />
            </div>
          </div>
        </div>
      </section>

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading title={t('Get started today')} size="md" />
          <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 lg:grid-cols-3">
            {educationSteps.map((step, index) => (
              <article
                key={step.title}
                className="flex h-full flex-col border-b border-border p-6 last:border-b-0 lg:border-b-0 lg:border-e lg:last:border-e-0"
              >
                <Badge variant="info" className="w-fit text-[10px] shrink-0">
                  {t('Step')} {index + 1}
                </Badge>
                <h3 className="mt-4 text-[14px] font-semibold text-foreground">{t(step.title)}</h3>
                <p className="mt-2 flex-1 text-[13px] leading-6 text-muted-foreground">
                  {t(step.description)}
                </p>
                <Button variant="outline" className="mt-6 w-fit" asChild>
                  {step.external ? (
                    <a href={step.href} target="_blank" rel="noopener noreferrer">
                      {t(step.label)}
                    </a>
                  ) : (
                    <Link
                      to={step.href}
                      search={{ redirect: '/' }}
                      {...(step.href === '/sign-up'
                        ? analyticsAttrs('education-sign-up')
                        : {})}
                    >
                      {t(step.label)}
                    </Link>
                  )}
                </Button>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="border-b border-border bg-muted/10 py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className={marketingSplitLayoutClassName({ align: 'center' })}>
            <div>
              <MarketingSectionHeading
                align="left"
                size="md"
                title={educationCommunity.title}
                description={educationCommunity.description}
              />
              <Button variant="outline" className="mt-6" asChild>
                <MarketingSiteLink href={educationCommunity.discordUrl}>
                  {t('Join Discord')}
                </MarketingSiteLink>
              </Button>
            </div>
            <CommunitySupportChat />
          </div>
        </div>
      </section>

      <MarketingFaqSection items={educationFaqItems} />

      <MarketingCtaSection title={educationCta.title} description={educationCta.description}>
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/' }}
            {...analyticsAttrs('education-sign-up')}
          >
            {t('Sign up')}
          </Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}

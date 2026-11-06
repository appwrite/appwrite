import { Button } from '@/components/ui/button'
import { MarketingHeroSection } from '@/components/pages/marketing/MarketingSections'
import { companyHero } from '@/lib/company/hero'
import { COMPANY_SECTION_IDS } from '@/lib/company/sections'
import { scrollToCompanySection } from '@/lib/company/section-scroll'
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
    <MarketingHeroSection
      title={companyHero.title}
      description={companyHero.lead}
      wideFooter
      footer={
        <div className="mt-10 border-t border-border/60 pt-8 text-start sm:mt-12 sm:pt-10">
          <div className="grid gap-8 md:grid-cols-2 md:gap-0">
            <div className="md:pe-10 lg:pe-12">
              <HeroColumn
                title={companyHero.mission.title}
                body={companyHero.mission.body}
              />
            </div>
            <div className="md:border-s md:border-border/60 md:ps-10 lg:ps-12">
              <HeroColumn
                title={companyHero.platform.title}
                body={companyHero.platform.body}
              />
            </div>
          </div>
          <p className="mt-8 text-[14px] font-medium text-foreground sm:mt-10 sm:text-[15px]">
            {t(companyHero.tagline)}
          </p>
        </div>
      }
    >
      <Button
        variant="brandCta"
        size="lg"
        className="h-10 text-[14px]"
        onClick={() => scrollToCompanySection(COMPANY_SECTION_IDS.careers)}
      >
        {t('Join the team')}
      </Button>
    </MarketingHeroSection>
  )
}

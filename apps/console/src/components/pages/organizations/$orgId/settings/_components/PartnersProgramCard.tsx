import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import {
  PARTNERS_FORM_ID,
  partnerBenefits,
  partnersHero,
} from '@/lib/partners/content'

export function PartnersProgramCard() {
  const t = useT()

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t(partnersHero.eyebrow)}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(partnersHero.description)}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Partner benefits designed to help you deliver more value to your clients.',
          )}
        </p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          {partnerBenefits.map((benefit) => {
            const Icon = benefit.icon
            return (
              <div key={benefit.title} className="flex items-start gap-3">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                  <Icon
                    className="h-4 w-4 text-muted-foreground"
                    aria-hidden
                  />
                </div>
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-foreground">
                    {t(benefit.title)}
                  </p>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    {t(benefit.description)}
                  </p>
                </div>
              </div>
            )
          })}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2 border-t border-border bg-muted/30 px-6 py-4">
        <Button size="sm" className="h-9 text-[13px]" asChild>
          <MarketingSiteLink
            href={`/partners#${PARTNERS_FORM_ID}`}
            {...analyticsAttrs('partners-become')}
          >
            {t('Become a Partner')}
          </MarketingSiteLink>
        </Button>
        <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
          <MarketingSiteLink href="/partners">
            {t('Learn more')}
          </MarketingSiteLink>
        </Button>
      </div>
    </div>
  )
}

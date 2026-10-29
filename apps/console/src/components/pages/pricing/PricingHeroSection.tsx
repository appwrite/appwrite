'use client'

import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { useT } from '@/lib/i18n/translate'
import { useStartPlanVisibility } from '@/hooks/use-start-plan-visibility'
import { cn } from '@/lib/utils'
import {
  PricingCardsGrid,
  getPricingPlanCardsContainerClassName,
  pricingHeroThreePlansContainerClassName,
} from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { PricingServicesAvatars } from './_components/PricingServicesAvatars'

export function PricingHeroSection() {
  const t = useT()
  const { ready, showStartPlan } = useStartPlanVisibility()
  return (
    <section className="relative isolate overflow-hidden border-b border-border bg-background pb-14 pt-10 sm:pb-16 sm:pt-14 lg:pb-20">
      <HomeSoftLights variant="pricing" />
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />
      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          title={
            <>
              {t('Everything your app needs,')}
              <br />
              {t('one subscription')}
            </>
          }
          description={t(
            'Build, deploy, secure, and observe your app from one platform, all under one subscription.',
          )}
        />
      </div>

      <div
        className={cn(
          'relative z-[1] mt-10 sm:mt-12 lg:mt-14',
          getPricingPlanCardsContainerClassName(
            ready,
            showStartPlan,
            pricingHeroThreePlansContainerClassName,
          ),
        )}
      >
        <PricingCardsGrid />
      </div>

      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingServicesAvatars />
      </div>
    </section>
  )
}

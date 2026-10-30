import type { ComponentType } from 'react'
import { Link } from '@tanstack/react-router'
import { SquareTerminal } from 'lucide-react'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { ProductFeatureSection } from '@/components/pages/products/ProductFeatureSection'
import { ProductCtaSection } from '@/components/pages/products/_components/ProductCtaSection'
import { ProductHero } from '@/components/pages/products/_components/ProductHero'
import { ProductHeroIcon } from '@/components/pages/products/_components/ProductHeroIcon'
import { ZeroAgentVisual } from '@/components/pages/zero/_components/ZeroAgentVisual'
import { ZeroClaimVisual } from '@/components/pages/zero/_components/ZeroClaimVisual'
import {
  ZeroCommandBar,
  ZeroPromptButton,
} from '@/components/pages/zero/_components/ZeroCommand'
import { ZeroLifecycleVisual } from '@/components/pages/zero/_components/ZeroLifecycleVisual'
import { ZeroServicesGrid } from '@/components/pages/zero/_components/ZeroServicesGrid'
import { ZeroWaysInVisual } from '@/components/pages/zero/_components/ZeroWaysInVisual'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { productToneAttrs } from '@/lib/products/theme'
import {
  zeroCta,
  zeroFaqItems,
  zeroFeatures,
  zeroHero,
} from '@/lib/zero/marketing-content'

const visuals: Record<string, ComponentType> = {
  agents: ZeroAgentVisual,
  lifecycle: ZeroLifecycleVisual,
  claim: ZeroClaimVisual,
  'ways-in': ZeroWaysInVisual,
}

export function View() {
  const t = useT()

  return (
    <div
      className="relative min-w-0 bg-background"
      {...productToneAttrs({ tone: 'pink', secondaryTone: 'purple' })}
    >
      <ProductHero
        layout="centered"
        badge={<ProductHeroIcon icon={SquareTerminal} name="Zero" />}
        title={zeroHero.title}
        description={zeroHero.description}
        stats={zeroHero.stats}
      >
        <ZeroCommandBar />
        <ZeroPromptButton />
      </ProductHero>

      {zeroFeatures.map((feature, index) => {
        const Visual = visuals[feature.id]
        return (
          <ProductFeatureSection
            key={feature.id}
            feature={feature}
            index={index}
            featureStyle="rail"
            visual={Visual ? <Visual /> : undefined}
            companion={
              feature.id === 'services' ? <ZeroServicesGrid /> : undefined
            }
          />
        )
      })}

      <MarketingFaqSection items={zeroFaqItems} />

      <ProductCtaSection
        icon={SquareTerminal}
        title={zeroCta.title}
        description={zeroCta.description}
      >
        <ZeroCommandBar />
        <Button
          variant="outline"
          size="lg"
          className="h-11 bg-background/60 text-[14px]"
          asChild
        >
          <Link
            to="/sign-up"
            search={{ redirect: '/' }}
            {...analyticsAttrs('zero-sign-up')}
          >
            {t('Sign up for free')}
          </Link>
        </Button>
      </ProductCtaSection>
    </div>
  )
}

import { ComparePlansSection } from './ComparePlansSection'
import { DatabasePricingSection } from './DatabasePricingSection'
import { FaqSection } from './FaqSection'
import { PricingCtaSection } from './PricingCtaSection'
import { PricingHashScroll } from './PricingHashScroll'
import { PricingHeroSection } from './PricingHeroSection'

export function View() {
  return (
    <div className="min-w-0">
      <PricingHeroSection />
      <DatabasePricingSection />
      <ComparePlansSection />
      <FaqSection />
      <PricingCtaSection />
      <PricingHashScroll />
    </div>
  )
}

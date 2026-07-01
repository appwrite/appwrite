import { ComparePlansSection } from './ComparePlansSection'
import { DatabasePricingSection } from './DatabasePricingSection'
import { FaqSection } from './FaqSection'
import { PricingCtaSection } from './PricingCtaSection'
import { PricingHashScroll } from './PricingHashScroll'
import {
  PricingHeroSection,
  StackConsolidationSection,
} from './PricingHeroSection'

export function View() {
  return (
    <div className="min-w-0">
      <PricingHeroSection />
      <StackConsolidationSection />
      <DatabasePricingSection />
      <ComparePlansSection />
      <FaqSection />
      <PricingCtaSection />
      <PricingHashScroll />
    </div>
  )
}

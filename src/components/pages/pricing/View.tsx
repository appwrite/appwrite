import { ComparePlansSection } from './ComparePlansSection'
import { FaqSection } from './FaqSection'
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
      <ComparePlansSection />
      <FaqSection />
      <PricingHashScroll />
    </div>
  )
}

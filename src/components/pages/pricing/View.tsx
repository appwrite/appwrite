import { ComparePlansSection } from './ComparePlansSection'
import { FaqSection } from './FaqSection'
import {
  PricingHeroSection,
  StackConsolidationSection,
} from './PricingHeroSection'

export function View() {
  return (
    <div className="overflow-x-hidden">
      <PricingHeroSection />
      <StackConsolidationSection />
      <ComparePlansSection />
      <FaqSection />
    </div>
  )
}

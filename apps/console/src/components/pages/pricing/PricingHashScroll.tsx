'use client'

import { usePricingHashScroll } from '@/hooks/use-pricing-hash-scroll'

/** Runs after the full pricing page is mounted so hash targets exist in the DOM. */
export function PricingHashScroll() {
  usePricingHashScroll()
  return null
}

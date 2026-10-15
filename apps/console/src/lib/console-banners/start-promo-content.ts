import type { StartPlanEligibleCountry } from '@/lib/pricing/start-plan'
import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type StartPromoBannerContent = {
  headline: string
  subline: string
  /** India only; other Start regions use text-only banners. */
  imageSrc?: string
}

const START_PROMO_CONTENT: Record<StartPlanEligibleCountry, StartPromoBannerContent> =
  {
    IN: {
      headline: 'Namaste India',
      subline: 'Appwrite Start, a plan built for Indian developers.',
      imageSrc: '/images/console/banners/start-promo-taj.svg',
    },
    NP: {
      headline: 'Namaste Nepal',
      subline: 'Appwrite Start, a plan built for Nepali developers.',
    },
    NG: {
      headline: 'Welcome Nigeria',
      subline: 'Appwrite Start, a plan built for Nigerian developers.',
    },
    PK: {
      headline: 'Welcome Pakistan',
      subline: 'Appwrite Start, a plan built for Pakistani developers.',
    },
  }

export function resolveStartPromoBannerContent(
  countryCode: string | null | undefined,
): StartPromoBannerContent | null {
  const normalized = normalizeCountryCode(countryCode)
  if (!normalized || !isStartPlanEligibleCountry(normalized)) {
    return null
  }
  return START_PROMO_CONTENT[normalized as StartPlanEligibleCountry] ?? null
}

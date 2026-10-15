import type { StartPlanEligibleCountry } from '@/lib/pricing/start-plan'
import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type StartPromoBannerContent = {
  headline: string
  subline: string
  imageSrc: string
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
      imageSrc: '/images/console/banners/start-promo-himalaya.svg',
    },
    NG: {
      headline: 'Welcome Nigeria',
      subline: 'Appwrite Start, a plan built for Nigerian developers.',
      imageSrc: '/images/console/banners/start-promo-nigeria.svg',
    },
    PK: {
      headline: 'Welcome Pakistan',
      subline: 'Appwrite Start, a plan built for Pakistani developers.',
      imageSrc: '/images/console/banners/start-promo-pakistan.svg',
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

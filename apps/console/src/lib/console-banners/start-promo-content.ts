import type { StartPlanEligibleCountry } from '@/lib/pricing/start-plan'
import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type StartPromoBannerContent = {
  headline: string
  subline: string
  imageSrc: string
  /** Taj is cropped into the header. These icons are shown whole. */
  imageClassName?: string
}

const LANDMARK_IMAGE_CLASS =
  'start-3 top-1/2 h-[108px] w-auto max-w-none -translate-y-1/2'

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
      imageSrc: '/images/console/banners/start-promo-himalaya.png',
      imageClassName: LANDMARK_IMAGE_CLASS,
    },
    NG: {
      headline: 'Welcome Nigeria',
      subline: 'Appwrite Start, a plan built for Nigerian developers.',
      imageSrc: '/images/console/banners/start-promo-nigeria.png',
      imageClassName: LANDMARK_IMAGE_CLASS,
    },
    PK: {
      headline: 'Welcome Pakistan',
      subline: 'Appwrite Start, a plan built for Pakistani developers.',
      imageSrc: '/images/console/banners/start-promo-pakistan.png',
      imageClassName: LANDMARK_IMAGE_CLASS,
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

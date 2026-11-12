import {
  START_EXPANSION_PROMO_BANNER_ID,
  START_PROMO_BANNER_ID,
} from '@/lib/console-banners/catalog'
import type { StartPlanEligibleCountry } from '@/lib/pricing/start-plan'
import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type StartPromoBannerContent = {
  /** Catalog banner that schedules and dismisses this country's promo. */
  bannerId: string
  headline: string
  subline: string
  /** Landmark line art in the Taj style, shown at the start of the banner. */
  imageSrc: string
}

const START_PROMO_CONTENT: Record<StartPlanEligibleCountry, StartPromoBannerContent> =
  {
    IN: {
      bannerId: START_PROMO_BANNER_ID,
      headline: 'Namaste India',
      subline: 'Appwrite Start, a plan built for Indian developers.',
      imageSrc: '/images/console/banners/start-promo-taj.svg',
    },
    NP: {
      bannerId: START_PROMO_BANNER_ID,
      headline: 'Namaste Nepal',
      subline: 'Appwrite Start, a plan built for Nepali developers.',
      imageSrc: '/images/console/banners/start-promo-boudha.svg',
    },
    NG: {
      bannerId: START_PROMO_BANNER_ID,
      headline: 'How far, Nigeria',
      subline: 'Appwrite Start, a plan built for Nigerian developers.',
      imageSrc: '/images/console/banners/start-promo-lekki.svg',
    },
    PK: {
      bannerId: START_PROMO_BANNER_ID,
      headline: 'Salaam Pakistan',
      subline: 'Appwrite Start, a plan built for Pakistani developers.',
      imageSrc: '/images/console/banners/start-promo-faisal.svg',
    },
    BD: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Salaam Bangladesh',
      subline: 'Appwrite Start, a plan built for Bangladeshi developers.',
      imageSrc: '/images/console/banners/start-promo-shaheed-minar.svg',
    },
    PH: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Mabuhay Philippines',
      subline: 'Appwrite Start, a plan built for Filipino developers.',
      imageSrc: '/images/console/banners/start-promo-rizal.svg',
    },
    EG: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Ahlan Egypt',
      subline: 'Appwrite Start, a plan built for Egyptian developers.',
      imageSrc: '/images/console/banners/start-promo-giza.svg',
    },
    KE: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Jambo Kenya',
      subline: 'Appwrite Start, a plan built for Kenyan developers.',
      imageSrc: '/images/console/banners/start-promo-kicc.svg',
    },
    VN: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Xin chào Vietnam',
      subline: 'Appwrite Start, a plan built for Vietnamese developers.',
      imageSrc: '/images/console/banners/start-promo-one-pillar.svg',
    },
    DZ: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Salam Algeria',
      subline: 'Appwrite Start, a plan built for Algerian developers.',
      imageSrc: '/images/console/banners/start-promo-maqam.svg',
    },
    MA: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Marhaba Morocco',
      subline: 'Appwrite Start, a plan built for Moroccan developers.',
      imageSrc: '/images/console/banners/start-promo-koutoubia.svg',
    },
    LK: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Ayubowan Sri Lanka',
      subline: 'Appwrite Start, a plan built for Sri Lankan developers.',
      imageSrc: '/images/console/banners/start-promo-lotus-tower.svg',
    },
    TN: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Aslema Tunisia',
      subline: 'Appwrite Start, a plan built for Tunisian developers.',
      imageSrc: '/images/console/banners/start-promo-el-jem.svg',
    },
    UZ: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Salom Uzbekistan',
      subline: 'Appwrite Start, a plan built for Uzbek developers.',
      imageSrc: '/images/console/banners/start-promo-registan.svg',
    },
    UG: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Oli otya, Uganda',
      subline: 'Appwrite Start, a plan built for Ugandan developers.',
      imageSrc: '/images/console/banners/start-promo-namugongo.svg',
    },
    GH: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Akwaaba Ghana',
      subline: 'Appwrite Start, a plan built for Ghanaian developers.',
      imageSrc: '/images/console/banners/start-promo-black-star.svg',
    },
    KH: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Suostei Cambodia',
      subline: 'Appwrite Start, a plan built for Cambodian developers.',
      imageSrc: '/images/console/banners/start-promo-angkor.svg',
    },
    ET: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Selam Ethiopia',
      subline: 'Appwrite Start, a plan built for Ethiopian developers.',
      imageSrc: '/images/console/banners/start-promo-axum.svg',
    },
    TZ: {
      bannerId: START_EXPANSION_PROMO_BANNER_ID,
      headline: 'Karibu Tanzania',
      subline: 'Appwrite Start, a plan built for Tanzanian developers.',
      imageSrc: '/images/console/banners/start-promo-kilimanjaro.svg',
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

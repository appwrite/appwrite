import { allHomeCaseStudies, shuffleHomeCaseStudies } from '@/lib/home/case-studies'

export type HomeCustomerLogo = {
  src: string
  alt: string
  width: number
  height: number
  mask?: boolean
  maskSrc?: string
  inverseMask?: boolean
  /** Slightly larger treatment for logos that read small at default scale. */
  size?: 'lg'
}

/** Enterprise logos from the homepage trusted-by strip. */
export const homeCustomerLogos: HomeCustomerLogo[] = [
  {
    src: '/images/logos/trusted-by/times-of-india.svg',
    alt: 'The Times of India',
    width: 123,
    height: 45,
    size: 'lg',
  },
  { src: '/images/logos/trusted-by/devkind.svg', alt: 'DevKind', width: 91, height: 27 },
  {
    src: '/images/logos/trusted-by/first-media.svg',
    alt: 'First Media',
    width: 139,
    height: 37,
    size: 'lg',
  },
  { src: '/images/logos/trusted-by/acer.svg', alt: 'Acer', width: 90, height: 22 },
  { src: '/images/logos/trusted-by/ibm.svg', alt: 'IBM', width: 63, height: 26 },
  {
    src: '/images/logos/trusted-by/american-airlines.svg',
    alt: 'American Airlines',
    width: 125,
    height: 20,
  },
  { src: '/images/logos/trusted-by/langx.svg', alt: 'LangX', width: 114, height: 25 },
  {
    src: '/images/logos/trusted-by/gm.svg',
    maskSrc: '/images/logos/trusted-by/gm-inverse-mask.svg',
    alt: 'GM',
    width: 41,
    height: 41,
    inverseMask: true,
  },
  { src: '/images/logos/trusted-by/ey.svg', alt: 'EY', width: 39, height: 41 },
  {
    src: '/images/logos/trusted-by/k-collect.svg',
    alt: 'K-Collect',
    width: 120,
    height: 35,
    mask: true,
  },
  { src: '/images/logos/trusted-by/bosch.svg', alt: 'BOSCH', width: 94, height: 31 },
  {
    src: '/images/logos/trusted-by/decathlon.svg',
    maskSrc: '/images/logos/trusted-by/decathlon-inverse-mask.svg',
    alt: 'DECATHLON',
    width: 108,
    height: 27,
    inverseMask: true,
  },
  {
    src: '/images/logos/trusted-by/store-alert.svg',
    alt: 'StoreAlert',
    width: 148,
    height: 32,
    size: 'lg',
  },
]

export const HOME_LOGO_GRID_COUNT = 12

/** All unique homepage customer logos (case studies + enterprise strip). */
export function buildAllHomeLogos(): HomeCustomerLogo[] {
  const logos: HomeCustomerLogo[] = []
  const seenSrcs = new Set<string>()

  for (const study of allHomeCaseStudies) {
    if (seenSrcs.has(study.logo)) continue
    seenSrcs.add(study.logo)
    logos.push({
      src: study.logo,
      alt: study.company,
      width: study.logoWidth,
      height: study.logoHeight,
      mask: study.logoMask,
      size: study.logoSize,
    })
  }

  for (const logo of homeCustomerLogos) {
    if (seenSrcs.has(logo.src)) continue
    seenSrcs.add(logo.src)
    logos.push(logo)
  }

  return logos
}

/** Stable list of every customer logo (case studies + enterprise strip). */
export const allCustomerLogos: HomeCustomerLogo[] = buildAllHomeLogos()

export function pickRandomHomeLogos(count = HOME_LOGO_GRID_COUNT): HomeCustomerLogo[] {
  const shuffled = shuffleHomeCaseStudies(buildAllHomeLogos())
  return shuffled.slice(0, Math.min(count, shuffled.length))
}

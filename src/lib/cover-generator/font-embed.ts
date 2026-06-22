import { readCoverPublicAssetDataUri } from '@/lib/cover-generator/public-assets'
const AEONIK_REGULAR = '/fonts/aeonik-pro/AeonikPro-Regular.woff2'
const AEONIK_MEDIUM = '/fonts/aeonik-pro/AeonikPro-Medium.woff2'
const INTER_REGULAR = '/fonts/inter/inter-v8-latin-regular.woff2'
const INTER_SEMIBOLD = '/fonts/inter/inter-v8-latin-600.woff2'

let cachedFontFaceCss: string | null = null

async function readFontDataUri(relativePath: string): Promise<string | null> {
  return readCoverPublicAssetDataUri(relativePath, 'font/woff2')
}

export async function getCoverFontFaceCss(): Promise<string> {
  if (cachedFontFaceCss) return cachedFontFaceCss

  const [aeonikRegular, aeonikMedium, interRegular, interSemibold] =
    await Promise.all([
      readFontDataUri(AEONIK_REGULAR),
      readFontDataUri(AEONIK_MEDIUM),
      readFontDataUri(INTER_REGULAR),
      readFontDataUri(INTER_SEMIBOLD),
    ])

  cachedFontFaceCss = `
    @font-face {
      font-family: 'Aeonik Pro';
      font-style: normal;
      font-weight: 400;
      src: url('${aeonikRegular ?? AEONIK_REGULAR}') format('woff2');
    }
    @font-face {
      font-family: 'Aeonik Pro';
      font-style: normal;
      font-weight: 500;
      src: url('${aeonikMedium ?? AEONIK_MEDIUM}') format('woff2');
    }
    @font-face {
      font-family: 'Inter';
      font-style: normal;
      font-weight: 400;
      src: url('${interRegular ?? INTER_REGULAR}') format('woff2');
    }
    @font-face {
      font-family: 'Inter';
      font-style: normal;
      font-weight: 600;
      src: url('${interSemibold ?? INTER_SEMIBOLD}') format('woff2');
    }
  `

  return cachedFontFaceCss
}

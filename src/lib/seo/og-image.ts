import {
  COVER_HEIGHT,
  COVER_WIDTH,
} from '@/lib/cover-generator/constants'
import { parseCoverRenderData } from '@/lib/cover-generator/parse-params'
import { DEFAULT_COVER_THEME_ID, type CoverThemeId } from '@/lib/cover-generator/themes'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'

export const OG_IMAGE_PATH = '/og/image.png'

export const OG_IMAGE_WIDTH = COVER_WIDTH
export const OG_IMAGE_HEIGHT = COVER_HEIGHT

export type OgImageParams = {
  title: string
  subtitle?: string
  eyebrow?: string
  cta?: string
  theme?: CoverThemeId
}

const OG_TITLE_MAX_LENGTH = 120
const OG_SUBTITLE_MAX_LENGTH = 160
const OG_EYEBROW_MAX_LENGTH = 40
const OG_CTA_MAX_LENGTH = 32

function truncateOgText(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, maxLength - 1).trimEnd()}…`
}

export function buildOgImageUrl(
  params: OgImageParams,
  siteOrigin?: string,
): string {
  const searchParams = new URLSearchParams()
  searchParams.set('title', truncateOgText(params.title, OG_TITLE_MAX_LENGTH))

  if (params.subtitle?.trim()) {
    searchParams.set(
      'subtitle',
      truncateOgText(params.subtitle, OG_SUBTITLE_MAX_LENGTH),
    )
  }

  if (params.eyebrow?.trim()) {
    searchParams.set(
      'eyebrow',
      truncateOgText(params.eyebrow, OG_EYEBROW_MAX_LENGTH),
    )
  }

  if (params.cta?.trim()) {
    searchParams.set('cta', truncateOgText(params.cta, OG_CTA_MAX_LENGTH))
  }

  if (params.theme) {
    searchParams.set('theme', params.theme)
  }

  return `${getSeoSiteOrigin(siteOrigin)}${OG_IMAGE_PATH}?${searchParams.toString()}`
}

export function parseOgImageRenderData(
  searchParams: URLSearchParams,
): CoverRenderData {
  const params = new URLSearchParams(searchParams)
  params.set('template', 'simple-title')
  params.set('format', 'png')
  params.set('width', String(OG_IMAGE_WIDTH))
  params.set('height', String(OG_IMAGE_HEIGHT))

  if (!params.get('theme')?.trim()) {
    params.set('theme', DEFAULT_COVER_THEME_ID)
  }

  return parseCoverRenderData(params)
}

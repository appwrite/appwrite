import {
  COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
  COVER_SCREENSHOT_ANGLED_3D_LIMITS,
  COVER_SCREENSHOT_ANGLED_LAYOUT,
} from '@/lib/perspective-screenshot-card/constants'
import type { CoverRenderData, CoverCardsAngledIconVisibility } from '@/lib/cover-generator/types'

export type { CoverCardsAngledIconVisibility } from '@/lib/cover-generator/types'

export const COVER_CARDS_ANGLED_ICON_VISIBILITY_OPTIONS = [
  { value: 'visible', label: 'Visible' },
  { value: 'fade', label: 'Faded' },
  { value: 'hidden', label: 'Hidden' },
] as const

export const COVER_CARDS_ANGLED_FADED_ICON_OPACITY = 0.35

export const COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY = 0.6

export const COVER_CARDS_ANGLED_ICON_SLOTS = 12

export const COVER_CARDS_ANGLED_HALO_GRID_COLUMNS = 6

export const COVER_CARDS_ANGLED_HALO_BOTTOM_ROWS = 2

export type CoverCardsAngledGridCell =
  | { kind: 'empty' }
  | { kind: 'filled'; iconIndex: number }

export type CoverCardsAngledHaloGrid = {
  columns: number
  rows: number
  cells: CoverCardsAngledGridCell[]
}

/**
 * Frame the filled icon block with empty cards on top, left, right, and bottom.
 * Default 4×3 selection sits in a 6-wide grid with two full-width bottom rows.
 */
export function buildCoverCardsAngledHaloGrid(
  filledColumns: number,
  filledRows: number,
): CoverCardsAngledHaloGrid {
  const gridColumns = Math.max(
    COVER_CARDS_ANGLED_HALO_GRID_COLUMNS,
    filledColumns + 2,
  )
  const gridRows = 1 + filledRows + COVER_CARDS_ANGLED_HALO_BOTTOM_ROWS
  const filledColStart = Math.floor((gridColumns - filledColumns) / 2)
  const filledRowStart = 1

  const cells: CoverCardsAngledGridCell[] = []

  for (let row = 0; row < gridRows; row += 1) {
    for (let col = 0; col < gridColumns; col += 1) {
      const inFilledRow =
        row >= filledRowStart && row < filledRowStart + filledRows
      const inFilledCol =
        col >= filledColStart && col < filledColStart + filledColumns

      if (inFilledRow && inFilledCol) {
        const iconIndex =
          (row - filledRowStart) * filledColumns + (col - filledColStart)
        cells.push({ kind: 'filled', iconIndex })
        continue
      }

      cells.push({ kind: 'empty' })
    }
  }

  return { columns: gridColumns, rows: gridRows, cells }
}

export const COVER_CARDS_ANGLED_DEFAULT_ICONS = [
  '/icons/appwrite.svg',
  '/icons/github.svg',
  '/icons/vercel.svg',
  '/icons/react.svg',
  '/icons/nextjs.svg',
  '/icons/supabase.svg',
  '/icons/stripe.svg',
  '/icons/docker.svg',
  '/icons/node.svg',
  '/icons/python.svg',
  '/icons/flutter.svg',
  '/icons/figma.svg',
] as const

export const COVER_CARDS_ANGLED_GRID = {
  columns: { min: 2, max: 6, default: 4 },
  rows: { min: 2, max: 4, default: 3 },
  iconSize: { min: 48, max: 140, default: 84, step: 4 },
  gap: { min: 8, max: 48, default: 20, step: 2 },
} as const

export const COVER_CARDS_ANGLED_3D_DEFAULTS = {
  ...COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
  displayScale: 1.9,
  translateX: -65,
  translateY: -90,
  posXRatio: COVER_SCREENSHOT_ANGLED_LAYOUT.offsetXRatio,
  posYRatio: COVER_SCREENSHOT_ANGLED_LAYOUT.offsetYRatio,
} as const

export { COVER_SCREENSHOT_ANGLED_3D_LIMITS as COVER_CARDS_ANGLED_3D_LIMITS }

export const COVER_CARDS_ANGLED_ICON_KEYS = [
  'icon1',
  'icon2',
  'icon3',
  'icon4',
  'icon5',
  'icon6',
  'icon7',
  'icon8',
  'icon9',
  'icon10',
  'icon11',
  'icon12',
] as const

export type CoverCardsAngledIconKey = (typeof COVER_CARDS_ANGLED_ICON_KEYS)[number]

export type CoverCardsAngledIconVisibilityKey = `${CoverCardsAngledIconKey}Visibility`

export function getCoverCardsAngledIconVisibilityKey(
  iconKey: CoverCardsAngledIconKey,
): CoverCardsAngledIconVisibilityKey {
  return `${iconKey}Visibility`
}

export function getCoverCardsAngledIconVisibilityKeys(): CoverCardsAngledIconVisibilityKey[] {
  return getCoverCardsAngledIconKeys().map(getCoverCardsAngledIconVisibilityKey)
}

export function parseCoverCardsAngledIconVisibility(
  value: unknown,
): CoverCardsAngledIconVisibility {
  if (value === 'hidden' || value === 'fade') return value
  return 'visible'
}

export type CoverCardsAngledIconSlot = {
  src: string
  visibility: CoverCardsAngledIconVisibility
}

export function getCoverCardsAngledIconKeys(): CoverCardsAngledIconKey[] {
  return [...COVER_CARDS_ANGLED_ICON_KEYS]
}

export function buildCoverCardsAngledDefaultIconParams(): Record<
  CoverCardsAngledIconKey,
  string
> {
  return Object.fromEntries(
    getCoverCardsAngledIconKeys().map((key, index) => [
      key,
      COVER_CARDS_ANGLED_DEFAULT_ICONS[index] ?? '/icons/appwrite.svg',
    ]),
  ) as Record<CoverCardsAngledIconKey, string>
}

export function getCoverCardsAngledLayoutResetFields() {
  return {
    columns: COVER_CARDS_ANGLED_GRID.columns.default,
    rows: COVER_CARDS_ANGLED_GRID.rows.default,
    iconSize: COVER_CARDS_ANGLED_GRID.iconSize.default,
    gap: COVER_CARDS_ANGLED_GRID.gap.default,
    ...COVER_CARDS_ANGLED_3D_DEFAULTS,
  }
}

export function normalizeCoverCardsAngledData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'cards-angled' }> {
  const cardsData = data.template === 'cards-angled' ? data : null
  const defaultIcons = buildCoverCardsAngledDefaultIconParams()
  const iconFields = Object.fromEntries(
    getCoverCardsAngledIconKeys().map((key) => [
      key,
      cardsData?.[key]?.trim() || defaultIcons[key],
    ]),
  ) as Record<CoverCardsAngledIconKey, string>

  const iconVisibilityFields = Object.fromEntries(
    getCoverCardsAngledIconKeys().map((key) => {
      const visibilityKey = getCoverCardsAngledIconVisibilityKey(key)
      return [
        visibilityKey,
        parseCoverCardsAngledIconVisibility(cardsData?.[visibilityKey]),
      ]
    }),
  ) as Record<CoverCardsAngledIconVisibilityKey, CoverCardsAngledIconVisibility>

  return {
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    template: 'cards-angled',
    columns:
      cardsData && Number.isFinite(cardsData.columns)
        ? cardsData.columns
        : COVER_CARDS_ANGLED_GRID.columns.default,
    rows:
      cardsData && Number.isFinite(cardsData.rows)
        ? cardsData.rows
        : COVER_CARDS_ANGLED_GRID.rows.default,
    iconSize:
      cardsData && Number.isFinite(cardsData.iconSize)
        ? cardsData.iconSize
        : COVER_CARDS_ANGLED_GRID.iconSize.default,
    gap:
      cardsData && Number.isFinite(cardsData.gap)
        ? cardsData.gap
        : COVER_CARDS_ANGLED_GRID.gap.default,
    rotateX:
      cardsData && Number.isFinite(cardsData.rotateX)
        ? cardsData.rotateX
        : COVER_CARDS_ANGLED_3D_DEFAULTS.rotateX,
    rotateZ:
      cardsData && Number.isFinite(cardsData.rotateZ)
        ? cardsData.rotateZ
        : COVER_CARDS_ANGLED_3D_DEFAULTS.rotateZ,
    rotateY:
      cardsData && Number.isFinite(cardsData.rotateY)
        ? cardsData.rotateY
        : COVER_CARDS_ANGLED_3D_DEFAULTS.rotateY,
    translateX:
      cardsData && Number.isFinite(cardsData.translateX)
        ? cardsData.translateX
        : COVER_CARDS_ANGLED_3D_DEFAULTS.translateX,
    translateY:
      cardsData && Number.isFinite(cardsData.translateY)
        ? cardsData.translateY
        : COVER_CARDS_ANGLED_3D_DEFAULTS.translateY,
    displayScale:
      cardsData && Number.isFinite(cardsData.displayScale)
        ? cardsData.displayScale
        : COVER_CARDS_ANGLED_3D_DEFAULTS.displayScale,
    posXRatio:
      cardsData && Number.isFinite(cardsData.posXRatio)
        ? cardsData.posXRatio
        : COVER_CARDS_ANGLED_3D_DEFAULTS.posXRatio,
    posYRatio:
      cardsData && Number.isFinite(cardsData.posYRatio)
        ? cardsData.posYRatio
        : COVER_CARDS_ANGLED_3D_DEFAULTS.posYRatio,
    ...iconFields,
    ...iconVisibilityFields,
  }
}

export function getCoverCardsAngledIconSlots(
  data: Extract<CoverRenderData, { template: 'cards-angled' }>,
): CoverCardsAngledIconSlot[] {
  const normalized = normalizeCoverCardsAngledData(data)
  const slotCount = Math.min(
    normalized.columns * normalized.rows,
    COVER_CARDS_ANGLED_ICON_SLOTS,
  )

  return getCoverCardsAngledIconKeys()
    .slice(0, slotCount)
    .map((key, index) => {
      const visibilityKey = getCoverCardsAngledIconVisibilityKey(key)
      const value = normalized[key]?.trim()
      return {
        src: value || COVER_CARDS_ANGLED_DEFAULT_ICONS[index] || '/icons/appwrite.svg',
        visibility: normalized[visibilityKey] ?? 'visible',
      }
    })
}

export function getCoverCardsAngledIcons(
  data: Extract<CoverRenderData, { template: 'cards-angled' }>,
): string[] {
  const normalized = normalizeCoverCardsAngledData(data)
  const slotCount = Math.min(
    normalized.columns * normalized.rows,
    COVER_CARDS_ANGLED_ICON_SLOTS,
  )

  return getCoverCardsAngledIconKeys()
    .slice(0, slotCount)
    .map((key, index) => {
      const value = normalized[key]?.trim()
      return value || COVER_CARDS_ANGLED_DEFAULT_ICONS[index] || '/icons/appwrite.svg'
    })
}

export function getCoverCardsAngledIconCardMetrics(iconSize: number) {
  const padding = Math.max(10, Math.round(iconSize * (28 / 120)))
  const cardSize = padding * 2 + iconSize
  const radius = Math.max(14, Math.round(iconSize * (32 / 120)))

  return { padding, cardSize, radius }
}

export function isCoverDomPreviewTemplate(template: string): boolean {
  return template === 'screenshot-angled' || template === 'cards-angled'
}

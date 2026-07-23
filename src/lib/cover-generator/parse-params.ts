import {
  COVER_HEIGHT,
  COVER_IMAGE_FORMATS,
  COVER_TEMPLATE_IDS,
  COVER_WIDTH,
  isCoverTemplateId,
  type CoverImageFormat,
  type CoverTemplateId,
  type CoverThemeId,
} from '@/lib/cover-generator/constants'
import {
  DEFAULT_COVER_THEME_ID,
  isCoverThemeId,
  resolveCoverThemeId,
} from '@/lib/cover-generator/themes'
import {
  formatCoverEyebrow,
  parseBooleanParam,
  parseNumberParam,
  stripCoverTitleSuffix,
} from '@/lib/cover-generator/text-utils'
import type { CoverRenderData, CoverScreenshotAngled3DFields, CoverScreenshotFields } from '@/lib/cover-generator/types'
import {
  COVER_SCREENSHOT_FRAME_HEIGHT,
  COVER_SCREENSHOT_FRAME_WIDTH,
} from '@/lib/cover-generator/cover-frame-width'
import {
  COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
  COVER_SCREENSHOT_ANGLED_3D_LIMITS,
} from '@/lib/perspective-screenshot-card/constants'
import {
  COVER_CARDS_ANGLED_3D_DEFAULTS,
  COVER_CARDS_ANGLED_GRID,
  buildCoverCardsAngledDefaultIconParams,
  getCoverCardsAngledIconKeys,
  getCoverCardsAngledIconVisibilityKey,
  parseCoverCardsAngledIconVisibility,
} from '@/lib/cover-generator/cards-angled/constants'
import {
  buildCoverTableDefaultFieldParams,
  COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT,
  COVER_TABLE_GRID,
  getCoverTableCellKey,
  getCoverTableHeaderKeys,
} from '@/lib/cover-generator/table/constants'
import {
  buildCoverChartDefaultFieldParams,
  COVER_CHART,
  COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
  getCoverChartLabelKeys,
  getCoverChartValueKeys,
} from '@/lib/cover-generator/chart/constants'
import {
  COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT,
  DEFAULT_CLI_CODE,
  DEFAULT_CLI_CODE_SUBTITLE,
  DEFAULT_CLI_CODE_TITLE,
  DEFAULT_CLI_TERMINAL_ICON,
  DEFAULT_CLI_TERMINAL_TITLE,
} from '@/lib/cover-generator/cli-code/constants'
import {
  COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT,
  COVER_CODE_SNIPPET_FONT_SIZE,
  DEFAULT_CODE_SNIPPET,
  DEFAULT_CODE_SNIPPET_LANGUAGE,
  DEFAULT_CODE_SNIPPET_TITLE,
  parseCoverCodeSnippetLanguage,
} from '@/lib/cover-generator/code-snippet/constants'
import { COVER_MILESTONE_DEFAULTS } from '@/lib/cover-generator/milestone/constants'
import { COVER_VERSION_DEFAULTS } from '@/lib/cover-generator/version/constants'

function parseCoverTitle(
  value: string | null | undefined,
  fallback: string,
): string {
  return stripCoverTitleSuffix(value?.trim() || fallback) || fallback
}

function parseCoverOptionalText(
  value: string | null | undefined,
): string | undefined {
  const raw = value?.trim()
  if (!raw) return undefined
  return stripCoverTitleSuffix(raw) || undefined
}

function parseCoverMilestoneFields(searchParams: URLSearchParams) {
  return {
    stat: searchParams.get('stat')?.trim() || COVER_MILESTONE_DEFAULTS.stat,
    statLabel: searchParams.get('statLabel')?.trim() || undefined,
    title: parseCoverTitle(searchParams.get('title'), COVER_MILESTONE_DEFAULTS.title),
    subtitle: searchParams.get('subtitle')?.trim() || undefined,
    eyebrow: formatCoverEyebrow(searchParams.get('eyebrow') ?? undefined),
    gradientStat: parseBooleanParam(
      searchParams.get('gradientStat'),
      COVER_MILESTONE_DEFAULTS.gradientStat,
    ),
  }
}

function appendCoverMilestoneSearchParams(
  params: URLSearchParams,
  data: {
    stat: string
    statLabel?: string
    title: string
    subtitle?: string
    eyebrow?: string
    gradientStat: boolean
  },
) {
  params.set('stat', data.stat)
  params.set('title', stripCoverTitleSuffix(data.title))
  const setOptional = (key: string, value: string | number | boolean | undefined) => {
    if (value == null || value === '') return
    params.set(key, String(value))
  }
  setOptional('statLabel', data.statLabel)
  setOptional('subtitle', data.subtitle)
  setOptional('eyebrow', formatCoverEyebrow(data.eyebrow))
  setOptional('gradientStat', data.gradientStat)
}

function parseCoverVersionNumberFields(searchParams: URLSearchParams) {
  return {
    version: searchParams.get('version')?.trim() || COVER_VERSION_DEFAULTS.version,
    eyebrow: formatCoverEyebrow(
      searchParams.get('eyebrow') ?? COVER_VERSION_DEFAULTS.eyebrow,
    ),
  }
}

function parseCoverVersionTitleFields(searchParams: URLSearchParams) {
  return {
    ...parseCoverVersionNumberFields(searchParams),
    title: parseCoverTitle(searchParams.get('title'), COVER_VERSION_DEFAULTS.title),
  }
}

function appendCoverVersionNumberSearchParams(
  params: URLSearchParams,
  data: {
    version: string
    eyebrow?: string
  },
) {
  params.set('version', data.version)
  const setOptional = (key: string, value: string | number | boolean | undefined) => {
    if (value == null || value === '') return
    params.set(key, String(value))
  }
  setOptional('eyebrow', formatCoverEyebrow(data.eyebrow))
}

function appendCoverVersionTitleSearchParams(
  params: URLSearchParams,
  data: {
    version: string
    title: string
    eyebrow?: string
  },
) {
  appendCoverVersionNumberSearchParams(params, data)
  params.set('title', stripCoverTitleSuffix(data.title))
}

export const DEFAULT_COVER_VALUES = {
  template: 'simple-title' satisfies CoverTemplateId,
  theme: DEFAULT_COVER_THEME_ID satisfies CoverThemeId,
  format: 'png' satisfies CoverImageFormat,
  width: COVER_WIDTH,
  height: COVER_HEIGHT,
  title: 'Build like a team of hundreds',
  subtitle: 'The open-source developer platform',
  eyebrow: 'Cover generator',
  connector: '×',
  iconSize: 120,
  integrationIconSize: 140,
  titleIconSize: 80,
  titleIconTitle: 'Auth',
  titleIconIcon: 'lucide:users',
  zoom: 1,
  focusX: 0,
  focusY: 0,
  frameWidthPercent: COVER_SCREENSHOT_FRAME_WIDTH.flatDefaultPercent,
  frameHeightPercent: COVER_SCREENSHOT_FRAME_HEIGHT.defaultPercent,
  screenshotAngledFrameWidthPercent: COVER_SCREENSHOT_FRAME_WIDTH.angledDefaultPercent,
  screenshotSideFrameWidthPercent: COVER_SCREENSHOT_FRAME_WIDTH.sideDefaultPercent,
  screenshotSideFrameHeightPercent: COVER_SCREENSHOT_FRAME_HEIGHT.sideDefaultPercent,
  logoLeft: '/icons/appwrite.svg',
  logoRight: '/icons/github.svg',
  icon: '/icons/appwrite.svg',
  screenshot: '',
} as const

function parseFormat(value: string | null): CoverImageFormat {
  const normalized = value?.trim().toLowerCase()
  if (normalized && (COVER_IMAGE_FORMATS as readonly string[]).includes(normalized)) {
    return normalized as CoverImageFormat
  }
  return DEFAULT_COVER_VALUES.format
}

function parseTemplate(value: string | null): CoverTemplateId {
  const normalized = value?.trim()
  if (normalized && isCoverTemplateId(normalized)) return normalized
  return DEFAULT_COVER_VALUES.template
}

function parseTheme(value: string | null): CoverThemeId {
  const normalized = value?.trim().toLowerCase()
  if (normalized && isCoverThemeId(normalized)) return normalized
  return DEFAULT_COVER_THEME_ID
}

function parseCoverScreenshotFields(
  searchParams: URLSearchParams,
  options: {
    frameWidthPercentDefault: number
    frameHeightPercentDefault?: number
    angled?: boolean
  },
): CoverScreenshotFields {
  const frameWidthLimits = COVER_SCREENSHOT_FRAME_WIDTH
  const frameHeightLimits = COVER_SCREENSHOT_FRAME_HEIGHT

  const frameWidthPercentRaw = searchParams.get('frameWidthPercent')
  let frameWidthPercent = options.frameWidthPercentDefault
  if (frameWidthPercentRaw != null && frameWidthPercentRaw !== '') {
    frameWidthPercent = parseNumberParam(
      frameWidthPercentRaw,
      options.frameWidthPercentDefault,
      frameWidthLimits.minPercent,
      frameWidthLimits.maxPercent,
    )
  }

  let frameHeightPercent: number | undefined
  if (!options.angled) {
    const frameHeightPercentRaw = searchParams.get('frameHeightPercent')
    const defaultHeightPercent =
      options.frameHeightPercentDefault ?? COVER_SCREENSHOT_FRAME_HEIGHT.defaultPercent

    frameHeightPercent = defaultHeightPercent
    if (frameHeightPercentRaw != null && frameHeightPercentRaw !== '') {
      frameHeightPercent = parseNumberParam(
        frameHeightPercentRaw,
        defaultHeightPercent,
        frameHeightLimits.minPercent,
        frameHeightLimits.maxPercent,
      )
    }
  }

  return {
    title: options.angled
      ? undefined
      : parseCoverOptionalText(searchParams.get('title')),
    subtitle: options.angled
      ? undefined
      : searchParams.get('subtitle')?.trim() || undefined,
    screenshot: searchParams.get('screenshot')?.trim() || undefined,
    zoom: parseNumberParam(
      searchParams.get('zoom'),
      DEFAULT_COVER_VALUES.zoom,
      1,
      3,
    ),
    focusX: parseNumberParam(
      searchParams.get('focusX'),
      DEFAULT_COVER_VALUES.focusX,
      0,
      100,
    ),
    focusY: parseNumberParam(
      searchParams.get('focusY'),
      DEFAULT_COVER_VALUES.focusY,
      0,
      100,
    ),
    frameWidthPercent,
    frameHeightPercent,
  }
}

function parseCoverScreenshotAngled3DFields(
  searchParams: URLSearchParams,
  defaults = COVER_SCREENSHOT_ANGLED_3D_DEFAULTS,
): CoverScreenshotAngled3DFields {
  const limits = COVER_SCREENSHOT_ANGLED_3D_LIMITS

  return {
    rotateX: parseNumberParam(
      searchParams.get('rotateX'),
      defaults.rotateX,
      limits.rotateX.min,
      limits.rotateX.max,
    ),
    rotateZ: parseNumberParam(
      searchParams.get('rotateZ'),
      defaults.rotateZ,
      limits.rotateZ.min,
      limits.rotateZ.max,
    ),
    rotateY: parseNumberParam(
      searchParams.get('rotateY'),
      defaults.rotateY,
      limits.rotateY.min,
      limits.rotateY.max,
    ),
    translateX: parseNumberParam(
      searchParams.get('translateX'),
      defaults.translateX,
      limits.translateX.min,
      limits.translateX.max,
    ),
    translateY: parseNumberParam(
      searchParams.get('translateY'),
      defaults.translateY,
      limits.translateY.min,
      limits.translateY.max,
    ),
    displayScale: parseNumberParam(
      searchParams.get('displayScale'),
      defaults.displayScale,
      limits.displayScale.min,
      limits.displayScale.max,
    ),
    posXRatio: parseNumberParam(
      searchParams.get('posXRatio'),
      defaults.posXRatio,
      limits.posXRatio.min,
      limits.posXRatio.max,
    ),
    posYRatio: parseNumberParam(
      searchParams.get('posYRatio'),
      defaults.posYRatio,
      limits.posYRatio.min,
      limits.posYRatio.max,
    ),
  }
}

function appendCoverScreenshotAngled3DSearchParams(
  params: URLSearchParams,
  data: CoverScreenshotAngled3DFields,
) {
  const setOptional = (key: string, value: string | number | boolean | undefined) => {
    if (value == null || value === '') return
    params.set(key, String(value))
  }

  setOptional('rotateX', data.rotateX)
  setOptional('rotateZ', data.rotateZ)
  setOptional('rotateY', data.rotateY)
  setOptional('translateX', data.translateX)
  setOptional('translateY', data.translateY)
  setOptional('displayScale', data.displayScale)
  setOptional('posXRatio', data.posXRatio)
  setOptional('posYRatio', data.posYRatio)
}

function appendCoverScreenshotSearchParams(
  params: URLSearchParams,
  data: CoverScreenshotFields,
  options?: { angled?: boolean },
) {
  const setOptional = (key: string, value: string | number | boolean | undefined) => {
    if (value == null || value === '') return
    params.set(key, String(value))
  }

  if (!options?.angled) {
    setOptional(
      'title',
      data.title ? stripCoverTitleSuffix(data.title) : undefined,
    )
    setOptional('subtitle', data.subtitle)
  }
  setOptional('screenshot', data.screenshot)
  setOptional('zoom', data.zoom)
  setOptional('focusX', data.focusX)
  setOptional('focusY', data.focusY)
  setOptional('frameWidthPercent', data.frameWidthPercent)
  if (!options?.angled) {
    setOptional('frameHeightPercent', data.frameHeightPercent)
  }
}

export function parseCoverRenderData(
  searchParams: URLSearchParams,
): CoverRenderData {
  const template = parseTemplate(searchParams.get('template'))
  const theme = parseTheme(searchParams.get('theme'))
  const format = parseFormat(searchParams.get('format'))
  const width = parseNumberParam(
    searchParams.get('width'),
    DEFAULT_COVER_VALUES.width,
    320,
    4096,
  )
  const height = parseNumberParam(
    searchParams.get('height'),
    DEFAULT_COVER_VALUES.height,
    200,
    4096,
  )

  const shared = {
    theme,
    format,
    width,
    height,
  }

  switch (template) {
    case 'integration':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        logoLeft: searchParams.get('logoLeft')?.trim() || undefined,
        logoRight: searchParams.get('logoRight')?.trim() || undefined,
        connector:
          searchParams.get('connector')?.trim() || DEFAULT_COVER_VALUES.connector,
      }
    case 'integration-icon':
      return {
        ...shared,
        template,
        icon: searchParams.get('icon')?.trim() || undefined,
        iconSize: parseNumberParam(
          searchParams.get('iconSize'),
          DEFAULT_COVER_VALUES.integrationIconSize,
          96,
          200,
        ),
      }
    case 'showcase-icon':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        icon: searchParams.get('icon')?.trim() || undefined,
        iconSize: parseNumberParam(
          searchParams.get('iconSize'),
          DEFAULT_COVER_VALUES.iconSize,
          48,
          220,
        ),
      }
    case 'title-icon':
      return {
        ...shared,
        template,
        title: parseCoverTitle(
          searchParams.get('title'),
          DEFAULT_COVER_VALUES.titleIconTitle,
        ),
        icon:
          searchParams.get('icon')?.trim() || DEFAULT_COVER_VALUES.titleIconIcon,
        iconSize: parseNumberParam(
          searchParams.get('iconSize'),
          DEFAULT_COVER_VALUES.titleIconSize,
          40,
          128,
        ),
      }
    case 'screenshot':
      return {
        ...shared,
        template,
        ...parseCoverScreenshotFields(searchParams, {
          frameWidthPercentDefault: DEFAULT_COVER_VALUES.frameWidthPercent,
          frameHeightPercentDefault: DEFAULT_COVER_VALUES.frameHeightPercent,
        }),
      }
    case 'screenshot-side':
      return {
        ...shared,
        template,
        ...parseCoverScreenshotFields(searchParams, {
          frameWidthPercentDefault: DEFAULT_COVER_VALUES.screenshotSideFrameWidthPercent,
          frameHeightPercentDefault: DEFAULT_COVER_VALUES.screenshotSideFrameHeightPercent,
        }),
      }
    case 'screenshot-angled':
      return {
        ...shared,
        template,
        ...parseCoverScreenshotFields(searchParams, {
          frameWidthPercentDefault: DEFAULT_COVER_VALUES.screenshotAngledFrameWidthPercent,
          angled: true,
        }),
        ...parseCoverScreenshotAngled3DFields(searchParams),
      }
    case 'cards-angled':
      return {
        ...shared,
        template,
        columns: parseNumberParam(
          searchParams.get('columns'),
          COVER_CARDS_ANGLED_GRID.columns.default,
          COVER_CARDS_ANGLED_GRID.columns.min,
          COVER_CARDS_ANGLED_GRID.columns.max,
        ),
        rows: parseNumberParam(
          searchParams.get('rows'),
          COVER_CARDS_ANGLED_GRID.rows.default,
          COVER_CARDS_ANGLED_GRID.rows.min,
          COVER_CARDS_ANGLED_GRID.rows.max,
        ),
        iconSize: parseNumberParam(
          searchParams.get('iconSize'),
          COVER_CARDS_ANGLED_GRID.iconSize.default,
          COVER_CARDS_ANGLED_GRID.iconSize.min,
          COVER_CARDS_ANGLED_GRID.iconSize.max,
        ),
        gap: parseNumberParam(
          searchParams.get('gap'),
          COVER_CARDS_ANGLED_GRID.gap.default,
          COVER_CARDS_ANGLED_GRID.gap.min,
          COVER_CARDS_ANGLED_GRID.gap.max,
        ),
        ...Object.fromEntries(
          getCoverCardsAngledIconKeys().flatMap((key) => {
            const visibilityKey = getCoverCardsAngledIconVisibilityKey(key)
            const visibility = parseCoverCardsAngledIconVisibility(
              searchParams.get(visibilityKey),
            )

            return [
              [key, searchParams.get(key)?.trim() || undefined],
              [visibilityKey, visibility],
            ] as const
          }),
        ),
        ...parseCoverScreenshotAngled3DFields(
          searchParams,
          COVER_CARDS_ANGLED_3D_DEFAULTS,
        ),
      }
    case 'table':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        columns: parseNumberParam(
          searchParams.get('columns'),
          COVER_TABLE_GRID.columns.default,
          COVER_TABLE_GRID.columns.min,
          COVER_TABLE_GRID.columns.max,
        ),
        rows: parseNumberParam(
          searchParams.get('rows'),
          COVER_TABLE_GRID.rows.default,
          COVER_TABLE_GRID.rows.min,
          COVER_TABLE_GRID.rows.max,
        ),
        showHeader: parseBooleanParam(searchParams.get('showHeader'), true),
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
        ...Object.fromEntries(
          getCoverTableHeaderKeys().map((key) => [
            key,
            searchParams.get(key)?.trim() || undefined,
          ]),
        ),
        ...Object.fromEntries(
          Array.from({ length: COVER_TABLE_GRID.rows.max }, (_, row) =>
            Array.from({ length: COVER_TABLE_GRID.columns.max }, (_, col) => {
              const key = getCoverTableCellKey(row, col)
              return [key, searchParams.get(key)?.trim() || undefined]
            }),
          ).flat(),
        ),
      }
    case 'bar-chart':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
        pointCount: parseNumberParam(
          searchParams.get('pointCount'),
          COVER_CHART.pointCount.default,
          COVER_CHART.pointCount.min,
          COVER_CHART.pointCount.max,
        ),
        showGrid: parseBooleanParam(searchParams.get('showGrid'), true),
        showValues: parseBooleanParam(searchParams.get('showValues'), false),
        ...Object.fromEntries(
          getCoverChartLabelKeys().map((key) => [
            key,
            searchParams.get(key)?.trim() || undefined,
          ]),
        ),
        ...Object.fromEntries(
          getCoverChartValueKeys().map((key) => [
            key,
            parseNumberParam(searchParams.get(key), 0, 0, 1_000_000),
          ]),
        ),
      }
    case 'line-chart':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
        pointCount: parseNumberParam(
          searchParams.get('pointCount'),
          COVER_CHART.pointCount.default,
          COVER_CHART.pointCount.min,
          COVER_CHART.pointCount.max,
        ),
        showGrid: parseBooleanParam(searchParams.get('showGrid'), true),
        showValues: parseBooleanParam(searchParams.get('showValues'), false),
        showArea: parseBooleanParam(searchParams.get('showArea'), true),
        ...Object.fromEntries(
          getCoverChartLabelKeys().map((key) => [
            key,
            searchParams.get(key)?.trim() || undefined,
          ]),
        ),
        ...Object.fromEntries(
          getCoverChartValueKeys().map((key) => [
            key,
            parseNumberParam(searchParams.get(key), 0, 0, 1_000_000),
          ]),
        ),
      }
    case 'cli-code':
      return {
        ...shared,
        template,
        title: parseCoverOptionalText(searchParams.get('title')),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        code: searchParams.get('code')?.trim() || DEFAULT_CLI_CODE,
        showPrompt: parseBooleanParam(searchParams.get('showPrompt'), true),
        terminalTitle:
          searchParams.get('terminalTitle')?.trim() || DEFAULT_CLI_TERMINAL_TITLE,
        terminalIcon:
          searchParams.get('terminalIcon')?.trim() || DEFAULT_CLI_TERMINAL_ICON,
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
      }
    case 'code-snippet':
      return {
        ...shared,
        template,
        title:
          parseCoverOptionalText(searchParams.get('title')) ?? DEFAULT_CODE_SNIPPET_TITLE,
        code: (() => {
          const raw = searchParams.get('code')
          return raw && raw.trim().length > 0 ? raw : DEFAULT_CODE_SNIPPET
        })(),
        language: parseCoverCodeSnippetLanguage(searchParams.get('language')),
        codeFontSize: parseNumberParam(
          searchParams.get('codeFontSize'),
          COVER_CODE_SNIPPET_FONT_SIZE.default,
          COVER_CODE_SNIPPET_FONT_SIZE.min,
          COVER_CODE_SNIPPET_FONT_SIZE.max,
        ),
        frameWidthPercent: parseNumberParam(
          searchParams.get('frameWidthPercent'),
          COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT,
          COVER_SCREENSHOT_FRAME_WIDTH.minPercent,
          COVER_SCREENSHOT_FRAME_WIDTH.maxPercent,
        ),
      }
    case 'milestone-split':
      return {
        ...shared,
        template,
        ...parseCoverMilestoneFields(searchParams),
      }
    case 'milestone-centered':
      return {
        ...shared,
        template,
        ...parseCoverMilestoneFields(searchParams),
      }
    case 'version-number':
      return {
        ...shared,
        template,
        ...parseCoverVersionNumberFields(searchParams),
      }
    case 'version-title':
      return {
        ...shared,
        template,
        ...parseCoverVersionTitleFields(searchParams),
      }
    case 'simple-title':
    default:
      return {
        ...shared,
        template: 'simple-title',
        title: parseCoverTitle(
          searchParams.get('title'),
          DEFAULT_COVER_VALUES.title,
        ),
        subtitle: searchParams.get('subtitle')?.trim() || undefined,
        eyebrow: formatCoverEyebrow(searchParams.get('eyebrow') ?? undefined),
        cta: searchParams.get('cta')?.trim() || undefined,
      }
  }
}

export function coverRenderDataToSearchParams(data: CoverRenderData): URLSearchParams {
  const params = new URLSearchParams()
  params.set('template', data.template)
  params.set('theme', data.theme)
  params.set('format', data.format)
  if (data.width !== COVER_WIDTH || data.height !== COVER_HEIGHT) {
    params.set('width', String(data.width))
    params.set('height', String(data.height))
  }

  const setOptional = (key: string, value: string | number | boolean | undefined) => {
    if (value == null || value === '') return
    params.set(key, String(value))
  }

  switch (data.template) {
    case 'simple-title':
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional('subtitle', data.subtitle)
      setOptional('eyebrow', formatCoverEyebrow(data.eyebrow))
      setOptional('cta', data.cta)
      break
    case 'integration':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('logoLeft', data.logoLeft)
      setOptional('logoRight', data.logoRight)
      setOptional('connector', data.connector)
      break
    case 'integration-icon':
      setOptional('icon', data.icon)
      setOptional('iconSize', data.iconSize)
      break
    case 'showcase-icon':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('icon', data.icon)
      setOptional('iconSize', data.iconSize)
      break
    case 'title-icon':
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional('icon', data.icon)
      setOptional('iconSize', data.iconSize)
      break
    case 'screenshot':
      appendCoverScreenshotSearchParams(params, data)
      break
    case 'screenshot-side':
      appendCoverScreenshotSearchParams(params, data)
      break
    case 'screenshot-angled':
      appendCoverScreenshotSearchParams(params, data, { angled: true })
      appendCoverScreenshotAngled3DSearchParams(params, data)
      break
    case 'cards-angled':
      setOptional('columns', data.columns)
      setOptional('rows', data.rows)
      setOptional('iconSize', data.iconSize)
      setOptional('gap', data.gap)
      for (const key of getCoverCardsAngledIconKeys()) {
        setOptional(key, data[key])
        const visibilityKey = getCoverCardsAngledIconVisibilityKey(key)
        const visibility = data[visibilityKey]
        if (visibility && visibility !== 'visible') {
          setOptional(visibilityKey, visibility)
        }
      }
      appendCoverScreenshotAngled3DSearchParams(params, data)
      break
    case 'table':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('columns', data.columns)
      setOptional('rows', data.rows)
      if (!data.showHeader) {
        setOptional('showHeader', data.showHeader)
      }
      setOptional('frameWidthPercent', data.frameWidthPercent)
      for (const key of getCoverTableHeaderKeys()) {
        setOptional(key, (data as Record<string, string | undefined>)[key])
      }
      for (let row = 0; row < COVER_TABLE_GRID.rows.max; row += 1) {
        for (let col = 0; col < COVER_TABLE_GRID.columns.max; col += 1) {
          const key = getCoverTableCellKey(row, col)
          setOptional(key, (data as Record<string, string | undefined>)[key])
        }
      }
      break
    case 'bar-chart':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('frameWidthPercent', data.frameWidthPercent)
      setOptional('pointCount', data.pointCount)
      if (!data.showGrid) {
        setOptional('showGrid', data.showGrid)
      }
      if (data.showValues) {
        setOptional('showValues', data.showValues)
      }
      for (const key of getCoverChartLabelKeys()) {
        setOptional(key, (data as Record<string, string | undefined>)[key])
      }
      for (const key of getCoverChartValueKeys()) {
        setOptional(key, (data as Record<string, number | undefined>)[key])
      }
      break
    case 'line-chart':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('frameWidthPercent', data.frameWidthPercent)
      setOptional('pointCount', data.pointCount)
      if (!data.showGrid) {
        setOptional('showGrid', data.showGrid)
      }
      if (data.showValues) {
        setOptional('showValues', data.showValues)
      }
      if (!data.showArea) {
        setOptional('showArea', data.showArea)
      }
      for (const key of getCoverChartLabelKeys()) {
        setOptional(key, (data as Record<string, string | undefined>)[key])
      }
      for (const key of getCoverChartValueKeys()) {
        setOptional(key, (data as Record<string, number | undefined>)[key])
      }
      break
    case 'cli-code':
      setOptional('title', data.title ? stripCoverTitleSuffix(data.title) : undefined)
      setOptional('subtitle', data.subtitle)
      setOptional('code', data.code)
      if (!data.showPrompt) {
        setOptional('showPrompt', data.showPrompt)
      }
      setOptional('terminalTitle', data.terminalTitle)
      setOptional('terminalIcon', data.terminalIcon)
      setOptional('frameWidthPercent', data.frameWidthPercent)
      break
    case 'code-snippet':
      params.set('title', stripCoverTitleSuffix(data.title))
      setOptional('code', data.code)
      setOptional('language', data.language)
      setOptional('codeFontSize', data.codeFontSize)
      setOptional('frameWidthPercent', data.frameWidthPercent)
      break
    case 'milestone-split':
    case 'milestone-centered':
      appendCoverMilestoneSearchParams(params, data)
      break
    case 'version-number':
      appendCoverVersionNumberSearchParams(params, data)
      break
    case 'version-title':
      appendCoverVersionTitleSearchParams(params, data)
      break
  }

  return params
}

export function createDefaultCoverData(
  template: CoverTemplateId = DEFAULT_COVER_VALUES.template,
  theme: CoverThemeId = DEFAULT_COVER_THEME_ID,
  options?: { width?: number; height?: number; format?: CoverImageFormat },
): CoverRenderData {
  const resolvedTheme = resolveCoverThemeId(theme)
  const screenshotDefaults =
    template === 'screenshot-angled'
      ? {
          frameWidthPercent: DEFAULT_COVER_VALUES.screenshotAngledFrameWidthPercent,
        }
      : template === 'screenshot-side'
        ? {
            frameWidthPercent: DEFAULT_COVER_VALUES.screenshotSideFrameWidthPercent,
            frameHeightPercent: DEFAULT_COVER_VALUES.screenshotSideFrameHeightPercent,
          }
        : {
            frameWidthPercent: DEFAULT_COVER_VALUES.frameWidthPercent,
            frameHeightPercent: DEFAULT_COVER_VALUES.frameHeightPercent,
          }

  const angled3dDefaults =
    template === 'screenshot-angled'
      ? COVER_SCREENSHOT_ANGLED_3D_DEFAULTS
      : template === 'cards-angled'
        ? COVER_CARDS_ANGLED_3D_DEFAULTS
        : null

  const cardsAngledIconParams =
    template === 'cards-angled' ? buildCoverCardsAngledDefaultIconParams() : {}

  const cardsAngledGridParams =
    template === 'cards-angled'
      ? {
          columns: String(COVER_CARDS_ANGLED_GRID.columns.default),
          rows: String(COVER_CARDS_ANGLED_GRID.rows.default),
          iconSize: String(COVER_CARDS_ANGLED_GRID.iconSize.default),
          gap: String(COVER_CARDS_ANGLED_GRID.gap.default),
          ...cardsAngledIconParams,
        }
      : {}

  const tableFieldParams = template === 'table' ? buildCoverTableDefaultFieldParams() : {}

  const chartFieldParams =
    template === 'bar-chart' || template === 'line-chart'
      ? buildCoverChartDefaultFieldParams()
      : {}

  const tableGridParams =
    template === 'table'
      ? {
          columns: String(COVER_TABLE_GRID.columns.default),
          rows: String(COVER_TABLE_GRID.rows.default),
          showHeader: String(true),
          frameWidthPercent: String(COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT),
          title: DEFAULT_COVER_VALUES.title,
          subtitle: DEFAULT_COVER_VALUES.subtitle,
          ...tableFieldParams,
        }
      : {}

  const chartGridParams =
    template === 'bar-chart'
      ? {
          pointCount: String(COVER_CHART.pointCount.default),
          showGrid: String(true),
          showValues: String(false),
          frameWidthPercent: String(COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT),
          title: 'API requests over time',
          subtitle: 'Monthly growth across all regions',
          ...chartFieldParams,
        }
      : template === 'line-chart'
        ? {
            pointCount: String(COVER_CHART.pointCount.default),
            showGrid: String(true),
            showValues: String(false),
            showArea: String(true),
            frameWidthPercent: String(COVER_CHART_DEFAULT_FRAME_WIDTH_PERCENT),
            title: 'Active users trend',
            subtitle: 'Rolling 30-day average',
            ...chartFieldParams,
          }
        : {}

  const cliCodeParams =
    template === 'cli-code'
      ? {
          title: DEFAULT_CLI_CODE_TITLE,
          subtitle: DEFAULT_CLI_CODE_SUBTITLE,
          code: DEFAULT_CLI_CODE,
          showPrompt: String(true),
          terminalTitle: DEFAULT_CLI_TERMINAL_TITLE,
          terminalIcon: DEFAULT_CLI_TERMINAL_ICON,
          frameWidthPercent: String(COVER_CLI_CODE_DEFAULT_FRAME_WIDTH_PERCENT),
        }
        : {}

  const codeSnippetParams =
    template === 'code-snippet'
      ? {
          title: DEFAULT_CODE_SNIPPET_TITLE,
          code: DEFAULT_CODE_SNIPPET,
          language: DEFAULT_CODE_SNIPPET_LANGUAGE,
          codeFontSize: String(COVER_CODE_SNIPPET_FONT_SIZE.default),
          frameWidthPercent: String(COVER_CODE_SNIPPET_DEFAULT_FRAME_WIDTH_PERCENT),
        }
      : {}

  const milestoneParams =
    template === 'milestone-split' || template === 'milestone-centered'
      ? {
          stat: COVER_MILESTONE_DEFAULTS.stat,
          statLabel: COVER_MILESTONE_DEFAULTS.statLabel,
          title: COVER_MILESTONE_DEFAULTS.title,
          subtitle: COVER_MILESTONE_DEFAULTS.subtitle,
          eyebrow: COVER_MILESTONE_DEFAULTS.eyebrow,
          gradientStat: String(COVER_MILESTONE_DEFAULTS.gradientStat),
        }
      : {}

  const versionParams =
    template === 'version-number'
      ? {
          version: COVER_VERSION_DEFAULTS.version,
          eyebrow: COVER_VERSION_DEFAULTS.eyebrow,
        }
      : template === 'version-title'
        ? {
            version: COVER_VERSION_DEFAULTS.version,
            eyebrow: COVER_VERSION_DEFAULTS.eyebrow,
            title: COVER_VERSION_DEFAULTS.title,
          }
        : {}

  const titleIconParams =
    template === 'title-icon'
      ? {
          title: DEFAULT_COVER_VALUES.titleIconTitle,
          icon: DEFAULT_COVER_VALUES.titleIconIcon,
        }
      : {}

  const defaults = parseCoverRenderData(
    new URLSearchParams({
      template,
      theme: resolvedTheme,
      format: options?.format ?? DEFAULT_COVER_VALUES.format,
      width: String(options?.width ?? DEFAULT_COVER_VALUES.width),
      height: String(options?.height ?? DEFAULT_COVER_VALUES.height),
      title: titleIconParams.title ?? DEFAULT_COVER_VALUES.title,
      subtitle: DEFAULT_COVER_VALUES.subtitle,
      eyebrow: DEFAULT_COVER_VALUES.eyebrow,
      connector: DEFAULT_COVER_VALUES.connector,
      iconSize: String(
        template === 'integration-icon'
          ? DEFAULT_COVER_VALUES.integrationIconSize
          : template === 'title-icon'
            ? DEFAULT_COVER_VALUES.titleIconSize
          : template === 'cards-angled'
            ? COVER_CARDS_ANGLED_GRID.iconSize.default
            : DEFAULT_COVER_VALUES.iconSize,
      ),
      logoLeft: DEFAULT_COVER_VALUES.logoLeft,
      logoRight: DEFAULT_COVER_VALUES.logoRight,
      icon: titleIconParams.icon ?? DEFAULT_COVER_VALUES.icon,
      zoom: String(DEFAULT_COVER_VALUES.zoom),
      focusX: String(DEFAULT_COVER_VALUES.focusX),
      focusY: String(DEFAULT_COVER_VALUES.focusY),
      frameWidthPercent: String(screenshotDefaults.frameWidthPercent),
      ...(screenshotDefaults.frameHeightPercent != null
        ? { frameHeightPercent: String(screenshotDefaults.frameHeightPercent) }
        : {}),
      ...cardsAngledGridParams,
      ...tableGridParams,
      ...chartGridParams,
      ...cliCodeParams,
      ...codeSnippetParams,
      ...milestoneParams,
      ...versionParams,
      ...(angled3dDefaults
        ? {
            rotateX: String(angled3dDefaults.rotateX),
            rotateZ: String(angled3dDefaults.rotateZ),
            rotateY: String(angled3dDefaults.rotateY),
            translateX: String(angled3dDefaults.translateX),
            translateY: String(angled3dDefaults.translateY),
            displayScale: String(angled3dDefaults.displayScale),
            posXRatio: String(angled3dDefaults.posXRatio),
            posYRatio: String(angled3dDefaults.posYRatio),
          }
        : {}),
    }),
  )
  return defaults
}

export function buildCoverApiUrl(
  data: CoverRenderData,
  origin = '',
): string {
  const params = coverRenderDataToSearchParams(data)
  const path = `/generator/cover?${params.toString()}`
  return origin ? `${origin.replace(/\/+$/, '')}${path}` : path
}

export { COVER_TEMPLATE_IDS, COVER_THEMES } from '@/lib/cover-generator/constants'

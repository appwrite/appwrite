import type {
  CoverImageFormat,
  CoverTemplateId,
} from '@/lib/cover-generator/constants'
import type { CoverBadgeColor, CoverThemeId } from '@/lib/cover-generator/themes'
import type { CoverCodeSnippetLanguage } from '@/lib/cover-generator/code-snippet/constants'
import { isCoverExtraTemplateId } from '@/lib/cover-generator/extra-templates/ids'

export type CoverScreenshotFields = {
  title?: string
  subtitle?: string
  screenshot?: string
  zoom: number
  focusX: number
  focusY: number
  /** Browser frame width as % of the template width (100 = edge to edge). */
  frameWidthPercent: number
  /** Browser frame height as % of the space below the title. Flat screenshot only. */
  frameHeightPercent?: number
}

export type CoverSimpleTitleData = {
  template: 'simple-title'
  title: string
  subtitle?: string
  eyebrow?: string
  cta?: string
}

export type CoverIntegrationData = {
  template: 'integration'
  title?: string
  subtitle?: string
  logoLeft?: string
  logoRight?: string
  connector: string
}

export type CoverIntegrationIconData = {
  template: 'integration-icon'
  icon?: string
  iconSize: number
}

export type CoverShowcaseIconData = {
  template: 'showcase-icon'
  title?: string
  subtitle?: string
  icon?: string
  iconSize: number
}

export type CoverTitleIconData = {
  template: 'title-icon'
  title: string
  icon?: string
  iconSize: number
}

export type CoverScreenshotData = CoverScreenshotFields & {
  template: 'screenshot'
}

export type CoverScreenshotSideData = CoverScreenshotFields & {
  template: 'screenshot-side'
}

export type CoverScreenshotAngled3DFields = {
  rotateX: number
  rotateZ: number
  rotateY: number
  translateX: number
  translateY: number
  displayScale: number
  posXRatio: number
  posYRatio: number
}

export type CoverScreenshotAngledData = CoverScreenshotFields &
  CoverScreenshotAngled3DFields & {
    template: 'screenshot-angled'
  }

export type CoverCardsAngledIconVisibility = 'visible' | 'hidden' | 'fade'

export type CoverCardsAngledData = CoverScreenshotAngled3DFields & {
  template: 'cards-angled'
  columns: number
  rows: number
  iconSize: number
  gap: number
  icon1?: string
  icon2?: string
  icon3?: string
  icon4?: string
  icon5?: string
  icon6?: string
  icon7?: string
  icon8?: string
  icon9?: string
  icon10?: string
  icon11?: string
  icon12?: string
  icon1Visibility?: CoverCardsAngledIconVisibility
  icon2Visibility?: CoverCardsAngledIconVisibility
  icon3Visibility?: CoverCardsAngledIconVisibility
  icon4Visibility?: CoverCardsAngledIconVisibility
  icon5Visibility?: CoverCardsAngledIconVisibility
  icon6Visibility?: CoverCardsAngledIconVisibility
  icon7Visibility?: CoverCardsAngledIconVisibility
  icon8Visibility?: CoverCardsAngledIconVisibility
  icon9Visibility?: CoverCardsAngledIconVisibility
  icon10Visibility?: CoverCardsAngledIconVisibility
  icon11Visibility?: CoverCardsAngledIconVisibility
  icon12Visibility?: CoverCardsAngledIconVisibility
}

export type CoverTableData = {
  template: 'table'
  title?: string
  subtitle?: string
  columns: number
  rows: number
  showHeader: boolean
  frameWidthPercent: number
} & Partial<Record<`header${number}`, string>> &
  Partial<Record<`cell_r${number}c${number}`, string>>

export type CoverChartPointFields = Partial<Record<`label${number}`, string>> &
  Partial<Record<`value${number}`, number>>

export type CoverBarChartData = {
  template: 'bar-chart'
  title?: string
  subtitle?: string
  frameWidthPercent: number
  pointCount: number
  showGrid: boolean
  showValues: boolean
} & CoverChartPointFields

export type CoverLineChartData = {
  template: 'line-chart'
  title?: string
  subtitle?: string
  frameWidthPercent: number
  pointCount: number
  showGrid: boolean
  showValues: boolean
  showArea: boolean
} & CoverChartPointFields

export type CoverCliCodeData = {
  template: 'cli-code'
  title?: string
  subtitle?: string
  code: string
  showPrompt: boolean
  terminalTitle: string
  terminalIcon?: string
  frameWidthPercent: number
}

export type CoverCodeSnippetData = {
  template: 'code-snippet'
  title: string
  code: string
  language: CoverCodeSnippetLanguage
  codeFontSize: number
  frameWidthPercent: number
}

export type CoverMilestoneBaseData = {
  stat: string
  statLabel?: string
  title: string
  subtitle?: string
  eyebrow?: string
}

export type CoverMilestoneSplitData = CoverMilestoneBaseData & {
  template: 'milestone-split'
}

export type CoverMilestoneCenteredData = CoverMilestoneBaseData & {
  template: 'milestone-centered'
}

export type CoverVersionNumberData = {
  template: 'version-number'
  version: string
  eyebrow?: string
}

export type CoverVersionTitleData = {
  template: 'version-title'
  version: string
  title: string
  eyebrow?: string
}

export type CoverAnnouncementData = {
  template: 'announcement'
  badge?: string
  title: string
  subtitle?: string
}

export type CoverBigTypeData = {
  template: 'big-type'
  title: string
  gradientTitle: boolean
}

export type CoverChecklistData = {
  template: 'checklist'
  title?: string
  subtitle?: string
  itemCount: number
} & Partial<Record<`item${number}`, string>>

export type CoverNumberedStepsData = {
  template: 'numbered-steps'
  eyebrow?: string
  title: string
  stepCount: number
} & Partial<Record<`stepTitle${number}` | `stepDescription${number}`, string>>

export type CoverApiEndpointMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export type CoverApiEndpointData = {
  template: 'api-endpoint'
  title?: string
  subtitle?: string
  method: CoverApiEndpointMethod
  path: string
  status?: string
  frameWidthPercent: number
}

export type CoverCodeDiffData = {
  template: 'code-diff'
  title?: string
  fileName: string
  code: string
  frameWidthPercent: number
}

export type CoverStatusPillData = {
  template: 'status-pill'
  eyebrow?: string
  status: string
  title: string
}

export type CoverCountdownData = {
  template: 'countdown'
  eyebrow?: string
  title: string
  days: number
  hours: number
  minutes: number
  seconds: number
  dateLabel?: string
}

export type CoverLogoMarqueeData = {
  template: 'logo-marquee'
  title?: string
  subtitle?: string
} & Partial<Record<`icon${number}`, string>>

export type CoverStatsGridData = {
  template: 'stats-grid'
  title?: string
  statCount: number
} & Partial<Record<`statValue${number}` | `statLabel${number}`, string>>

export type CoverMetricDeltaTone = 'up' | 'down'

export type CoverMetricDeltaData = {
  template: 'metric-delta'
  label?: string
  value: string
  delta?: string
  deltaTone: CoverMetricDeltaTone
} & Partial<Record<`trend${number}`, number>>

export type CoverDonutChartData = {
  template: 'donut-chart'
  title?: string
  subtitle?: string
  percent: number
  centerLabel?: string
  color: CoverBadgeColor
}

export type CoverProgressBarData = {
  template: 'progress-bar'
  title?: string
  percent: number
  label?: string
  color: CoverBadgeColor
}

export type CoverQuoteData = {
  template: 'quote'
  quote: string
  authorName?: string
  authorRole?: string
  avatar?: string
}

export type CoverBlogPostData = {
  template: 'blog-post'
  category?: string
  title: string
  authorName?: string
  avatar?: string
  date?: string
  readTime?: string
}

export type CoverPodcastEpisodeData = {
  template: 'podcast-episode'
  episode?: string
  title: string
  duration?: string
  host?: string
}

export type CoverEventData = {
  template: 'event'
  month: string
  day: string
  title: string
  location?: string
  cta?: string
}

export type CoverProfileCardData = {
  template: 'profile-card'
  avatar?: string
  name: string
  role?: string
  handle?: string
}

export type CoverSocialPostData = {
  template: 'social-post'
  avatar?: string
  name: string
  handle?: string
  time?: string
  text: string
  likes?: string
  comments?: string
}

/** Union of the second batch of cover templates (see `extra-templates/ids.ts`). */
export type CoverExtraTemplateData =
  | CoverAnnouncementData
  | CoverBigTypeData
  | CoverChecklistData
  | CoverNumberedStepsData
  | CoverApiEndpointData
  | CoverCodeDiffData
  | CoverStatusPillData
  | CoverCountdownData
  | CoverLogoMarqueeData
  | CoverStatsGridData
  | CoverMetricDeltaData
  | CoverDonutChartData
  | CoverProgressBarData
  | CoverQuoteData
  | CoverBlogPostData
  | CoverPodcastEpisodeData
  | CoverEventData
  | CoverProfileCardData
  | CoverSocialPostData

export type CoverTemplateData =
  | CoverSimpleTitleData
  | CoverIntegrationData
  | CoverIntegrationIconData
  | CoverShowcaseIconData
  | CoverTitleIconData
  | CoverScreenshotData
  | CoverScreenshotSideData
  | CoverScreenshotAngledData
  | CoverCardsAngledData
  | CoverTableData
  | CoverBarChartData
  | CoverLineChartData
  | CoverCliCodeData
  | CoverCodeSnippetData
  | CoverMilestoneSplitData
  | CoverMilestoneCenteredData
  | CoverVersionNumberData
  | CoverVersionTitleData
  | CoverExtraTemplateData

export type CoverRenderData = {
  theme: CoverThemeId
  format: CoverImageFormat
  width: number
  height: number
} & CoverTemplateData

/** Narrows render data to one of the extra (second-batch) templates. */
export function isCoverExtraTemplateData(
  data: CoverRenderData,
): data is CoverRenderData & CoverExtraTemplateData {
  return isCoverExtraTemplateId(data.template)
}

export type CoverFieldType =
  | 'text'
  | 'textarea'
  | 'code'
  | 'image'
  | 'boolean'
  | 'number'
  | 'range'
  | 'select'
  | 'color'
  | 'date'

export type CoverFieldDefinition = {
  key: string
  label: string
  type: CoverFieldType
  placeholder?: string
  min?: number
  max?: number
  step?: number
  unit?: string
  options?: Array<{ value: string; label: string; swatchClass?: string }>
  description?: string
  /** When set on image fields, shows the built-in icon picker above custom URL/upload. */
  imagePicker?: 'builtin-icons'
  /** When type is `code`, reads the Monaco language from this render-data key. */
  codeLanguageField?: string
}

export type CoverTemplateDefinition = {
  id: CoverTemplateId
  label: string
  description: string
  fields: CoverFieldDefinition[]
}

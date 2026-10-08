import { renderApiEndpointTemplateSvg, renderCodeDiffTemplateSvg } from '@/lib/cover-generator/extra-templates/render-code'
import {
  renderDonutChartTemplateSvg,
  renderMetricDeltaTemplateSvg,
  renderProgressBarTemplateSvg,
  renderStatsGridTemplateSvg,
} from '@/lib/cover-generator/extra-templates/render-data'
import { renderLogoMarqueeTemplateSvg } from '@/lib/cover-generator/extra-templates/render-logos'
import { renderCountdownTemplateSvg, renderStatusPillTemplateSvg } from '@/lib/cover-generator/extra-templates/render-releases'
import {
  renderBlogPostTemplateSvg,
  renderEventTemplateSvg,
  renderPodcastEpisodeTemplateSvg,
  renderProfileCardTemplateSvg,
  renderQuoteTemplateSvg,
  renderSocialPostTemplateSvg,
} from '@/lib/cover-generator/extra-templates/render-social'
import {
  renderAnnouncementTemplateSvg,
  renderBigTypeTemplateSvg,
  renderChecklistTemplateSvg,
  renderNumberedStepsTemplateSvg,
} from '@/lib/cover-generator/extra-templates/render-text'
import type { CoverThemeId } from '@/lib/cover-generator/themes'
import type { CoverExtraTemplateData } from '@/lib/cover-generator/types'

/** Render the SVG content fragment for any extra (second-batch) cover template. */
export async function renderCoverExtraTemplateSvg(
  data: CoverExtraTemplateData & { width: number; height: number },
  themeId: CoverThemeId,
): Promise<string> {
  switch (data.template) {
    case 'announcement':
      return renderAnnouncementTemplateSvg(data, themeId)
    case 'big-type':
      return renderBigTypeTemplateSvg(data, themeId)
    case 'checklist':
      return renderChecklistTemplateSvg(data, themeId)
    case 'numbered-steps':
      return renderNumberedStepsTemplateSvg(data, themeId)
    case 'api-endpoint':
      return renderApiEndpointTemplateSvg(data, themeId)
    case 'code-diff':
      return renderCodeDiffTemplateSvg(data, themeId)
    case 'status-pill':
      return renderStatusPillTemplateSvg(data, themeId)
    case 'countdown':
      return renderCountdownTemplateSvg(data, themeId)
    case 'logo-marquee':
      return renderLogoMarqueeTemplateSvg(data, themeId)
    case 'stats-grid':
      return renderStatsGridTemplateSvg(data, themeId)
    case 'metric-delta':
      return renderMetricDeltaTemplateSvg(data, themeId)
    case 'donut-chart':
      return renderDonutChartTemplateSvg(data, themeId)
    case 'progress-bar':
      return renderProgressBarTemplateSvg(data, themeId)
    case 'quote':
      return renderQuoteTemplateSvg(data, themeId)
    case 'blog-post':
      return renderBlogPostTemplateSvg(data, themeId)
    case 'podcast-episode':
      return renderPodcastEpisodeTemplateSvg(data, themeId)
    case 'event':
      return renderEventTemplateSvg(data, themeId)
    case 'profile-card':
      return renderProfileCardTemplateSvg(data, themeId)
    case 'social-post':
      return renderSocialPostTemplateSvg(data, themeId)
  }
}

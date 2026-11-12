/**
 * Additional cover template ids (second batch of templates).
 * Spread into COVER_TEMPLATE_IDS in `cover-generator/constants.ts`.
 */
export const COVER_EXTRA_TEMPLATE_IDS = [
  'announcement',
  'big-type',
  'checklist',
  'numbered-steps',
  'api-endpoint',
  'code-diff',
  'status-pill',
  'countdown',
  'logo-marquee',
  'stats-grid',
  'metric-delta',
  'donut-chart',
  'progress-bar',
  'quote',
  'blog-post',
  'podcast-episode',
  'event',
  'profile-card',
  'social-post',
] as const

export type CoverExtraTemplateId = (typeof COVER_EXTRA_TEMPLATE_IDS)[number]

export function isCoverExtraTemplateId(value: string): value is CoverExtraTemplateId {
  return (COVER_EXTRA_TEMPLATE_IDS as readonly string[]).includes(value)
}

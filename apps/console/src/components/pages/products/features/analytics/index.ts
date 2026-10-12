import type { ComponentType } from 'react'
import { AnalyticsAiTrafficVisual } from './AnalyticsAiTrafficVisual'
import { AnalyticsCustomEventsVisual } from './AnalyticsCustomEventsVisual'
import { AnalyticsDashboardVisual } from './AnalyticsDashboardVisual'
import { AnalyticsMcpVisual } from './AnalyticsMcpVisual'
import { AnalyticsPlatformVisual } from './AnalyticsPlatformVisual'
import { AnalyticsServerSideVisual } from './AnalyticsServerSideVisual'

/** Feature visuals by feature id. `privacy` uses the companion catalog instead. */
export const ANALYTICS_FEATURE_VISUALS: Record<string, ComponentType> = {
  dashboard: AnalyticsDashboardVisual,
  'ai-traffic': AnalyticsAiTrafficVisual,
  'custom-events': AnalyticsCustomEventsVisual,
  'server-side': AnalyticsServerSideVisual,
  mcp: AnalyticsMcpVisual,
  platform: AnalyticsPlatformVisual,
}

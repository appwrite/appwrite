import type { ComponentType } from 'react'
import { AnalyticsAiTrafficVisual } from './AnalyticsAiTrafficVisual'
import { AnalyticsAppsVisual } from './AnalyticsAppsVisual'
import { AnalyticsCustomEventsVisual } from './AnalyticsCustomEventsVisual'
import { AnalyticsDashboardVisual } from './AnalyticsDashboardVisual'
import { AnalyticsMcpVisual } from './AnalyticsMcpVisual'
import { AnalyticsPlatformVisual } from './AnalyticsPlatformVisual'
import { AnalyticsServerSideVisual } from './AnalyticsServerSideVisual'
import { AnalyticsSitesVisual } from './AnalyticsSitesVisual'
import { AnalyticsUsersVisual } from './AnalyticsUsersVisual'

/** Feature visuals by feature id. `privacy` uses the companion catalog instead. */
export const ANALYTICS_FEATURE_VISUALS: Record<string, ComponentType> = {
  sites: AnalyticsSitesVisual,
  users: AnalyticsUsersVisual,
  dashboard: AnalyticsDashboardVisual,
  apps: AnalyticsAppsVisual,
  'ai-traffic': AnalyticsAiTrafficVisual,
  'custom-events': AnalyticsCustomEventsVisual,
  'server-side': AnalyticsServerSideVisual,
  mcp: AnalyticsMcpVisual,
  platform: AnalyticsPlatformVisual,
}

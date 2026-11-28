import type { ComponentType } from 'react'
import { ReviewsAgentsVisual } from './ReviewsAgentsVisual'
import { ReviewsChatVisual } from './ReviewsChatVisual'
import { ReviewsConnectVisual } from './ReviewsConnectVisual'
import { ReviewsFindingVisual } from './ReviewsFindingVisual'
import { ReviewsLearningsVisual } from './ReviewsLearningsVisual'
import { ReviewsPerformanceVisual } from './ReviewsPerformanceVisual'
import { ReviewsPlatformCatalog } from './ReviewsPlatformCatalog'
import { ReviewsStrengthVisual } from './ReviewsStrengthVisual'
import { ReviewsSummaryVisual } from './ReviewsSummaryVisual'
import { ReviewsTraceVisual } from './ReviewsTraceVisual'

/** Feature visuals by feature id. `platform` uses the companion instead. */
export const REVIEWS_FEATURE_VISUALS: Record<string, ComponentType> = {
  connect: ReviewsConnectVisual,
  review: ReviewsSummaryVisual,
  findings: ReviewsFindingVisual,
  verified: ReviewsTraceVisual,
  conversation: ReviewsChatVisual,
  learnings: ReviewsLearningsVisual,
  strength: ReviewsStrengthVisual,
  agents: ReviewsAgentsVisual,
  performance: ReviewsPerformanceVisual,
}

/** Wide companions for stacked sections, by feature id. */
export const REVIEWS_FEATURE_COMPANIONS: Record<string, ComponentType> = {
  platform: ReviewsPlatformCatalog,
}

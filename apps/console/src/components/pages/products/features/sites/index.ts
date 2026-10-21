import type { ComponentType } from 'react'
import { SitesBuildPerformanceVisual } from '@/components/pages/products/features/sites/SitesBuildPerformanceVisual'
import { SitesDomainRulesVisual } from '@/components/pages/products/features/sites/SitesDomainRulesVisual'
import { SitesGitPreviewsVisual } from '@/components/pages/products/features/sites/SitesGitPreviewsVisual'
import { SitesObservabilityVisual } from '@/components/pages/products/features/sites/SitesObservabilityVisual'
import { SitesRenderingVisual } from '@/components/pages/products/features/sites/SitesRenderingVisual'
import { SitesRollbacksVisual } from '@/components/pages/products/features/sites/SitesRollbacksVisual'
import { SitesTemplatesVisual } from '@/components/pages/products/features/sites/SitesTemplatesVisual'

export const SITES_FEATURE_VISUALS: Record<string, ComponentType> = {
  'git-previews': SitesGitPreviewsVisual,
  rendering: SitesRenderingVisual,
  builds: SitesBuildPerformanceVisual,
  rollbacks: SitesRollbacksVisual,
  observability: SitesObservabilityVisual,
  'domain-rules': SitesDomainRulesVisual,
  templates: SitesTemplatesVisual,
}

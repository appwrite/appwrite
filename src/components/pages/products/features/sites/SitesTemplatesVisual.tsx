import { SiteTemplateGallery } from '@/components/pages/projects/$projectId/sites/_components/SiteTemplateGallery'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import {
  MARKETING_SITE_TEMPLATES_COLUMNS,
  MARKETING_SITE_TEMPLATES_PAGE_SIZE,
  MARKETING_SITE_TEMPLATES_PROJECT_ID,
} from '@/lib/sites/site-template-wizard'

export function SitesTemplatesVisual() {
  return (
    <ProductFeatureVisualFrame eyebrow="Create site" title="Clone template">
      <SiteTemplateGallery
        projectId={MARKETING_SITE_TEMPLATES_PROJECT_ID}
        columns={MARKETING_SITE_TEMPLATES_COLUMNS}
        compact
        maintainGridHeight
        scrollToTopOnPageChange={false}
        defaultPageSize={MARKETING_SITE_TEMPLATES_PAGE_SIZE}
        pageSizeOptions={[MARKETING_SITE_TEMPLATES_PAGE_SIZE]}
      />
    </ProductFeatureVisualFrame>
  )
}

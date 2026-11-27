import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getPricingSchema } from '@/lib/pricing/seo'
import { getOrganizationSchema } from '@/lib/seo/organization'
import { SEO_SITE_NAME } from '@/lib/seo/page-meta'
import type { ProductId } from '@/lib/products/types'

/**
 * A product page as a schema.org `SoftwareApplication` sold on the Cloud
 * plans, with a breadcrumb from the homepage. Search engines read the plans'
 * prices and the page's place in the site from it.
 */
export function getProductSchema(input: {
  id: ProductId
  name: string
  description: string
}) {
  const url = `${MARKETING_SITE_ORIGIN}/products/${input.id}`
  const name = `${SEO_SITE_NAME} ${input.name}`

  return [
    {
      '@context': 'https://schema.org',
      '@type': 'SoftwareApplication',
      name,
      description: input.description,
      applicationCategory: 'DeveloperApplication',
      operatingSystem: 'Web',
      url,
      publisher: getOrganizationSchema(),
      offers: getPricingSchema().offers,
    },
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        {
          '@type': 'ListItem',
          position: 1,
          name: SEO_SITE_NAME,
          item: MARKETING_SITE_ORIGIN,
        },
        {
          '@type': 'ListItem',
          position: 2,
          name: input.name,
          item: url,
        },
      ],
    },
  ]
}

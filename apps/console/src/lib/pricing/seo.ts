import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getOrganizationSchema } from '@/lib/seo/organization'
import { pricingPlans } from './plans'

/**
 * Appwrite Cloud plans with a listed USD price, as schema.org offers. Start is
 * country-gated and Enterprise is quoted, so neither is included.
 */
export function getPricingSchema() {
  const url = `${MARKETING_SITE_ORIGIN}/pricing`

  return {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'Appwrite Cloud',
    applicationCategory: 'DeveloperApplication',
    operatingSystem: 'Web',
    url,
    publisher: getOrganizationSchema(),
    offers: pricingPlans
      .filter((plan) => plan.price.startsWith('$'))
      .map((plan) => {
        const price = plan.price.slice(1)
        return {
          '@type': 'Offer',
          name: plan.name,
          description: plan.description,
          price,
          priceCurrency: 'USD',
          url,
          ...(plan.priceSuffix === '/month'
            ? {
                priceSpecification: {
                  '@type': 'UnitPriceSpecification',
                  price,
                  priceCurrency: 'USD',
                  unitCode: 'MON',
                },
              }
            : {}),
        }
      }),
  }
}

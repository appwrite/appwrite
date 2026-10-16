import { describe, expect, test } from 'bun:test'
import { getChangelogEntrySchema } from '@/lib/changelog/seo'
import { pricingPlans } from '@/lib/pricing/plans'
import { getPricingSchema } from '@/lib/pricing/seo'
import { getOrganizationSchema } from '@/lib/seo/organization'

describe('organization schema', () => {
  test('links the official profiles', () => {
    const organization = getOrganizationSchema()

    expect(organization['@id']).toBe('https://appwrite.io/#organization')
    expect(organization.sameAs).toContain('https://github.com/appwrite')
    expect(organization.sameAs).toContain('https://x.com/appwrite')
  })
})

describe('pricing schema', () => {
  test('lists every plan with a USD price and skips quoted plans', () => {
    const offers = getPricingSchema().offers
    const listed = pricingPlans.filter((plan) => plan.price.startsWith('$'))

    expect(offers.map((offer) => offer.name)).toEqual(
      listed.map((plan) => plan.name),
    )
    expect(offers.map((offer) => offer.name)).not.toContain('Enterprise')
  })

  test('matches each plan price, billing period, and "From" floor', () => {
    const offers = getPricingSchema().offers

    for (const offer of offers) {
      const plan = pricingPlans.find((item) => item.name === offer.name)!
      const monthly = plan.priceSuffix === '/month'

      expect(`$${offer.price}`).toBe(plan.price)
      expect(offer.priceCurrency).toBe('USD')
      expect(offer.priceSpecification?.unitCode).toBe(
        monthly ? 'MON' : undefined,
      )
      expect(offer.priceSpecification?.minPrice).toBe(
        plan.pricePrefix === 'From' ? offer.price : undefined,
      )
    }
  })
})

describe('changelog entry schema', () => {
  test('makes a relative cover an absolute image URL', () => {
    const schema = getChangelogEntrySchema({
      title: 'Helm chart',
      date: '2026-10-15',
      cover: '/images/changelog/helm/cover.avif',
      slug: '2026-10-15',
      href: '/changelog/entry/2026-10-15',
      parsedTags: [],
      content: '',
    })

    expect(schema.image).toEqual([
      'https://appwrite.io/images/changelog/helm/cover.avif',
    ])
  })
})

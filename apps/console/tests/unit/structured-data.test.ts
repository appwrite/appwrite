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
    for (const offer of offers) {
      expect(offer.priceCurrency).toBe('USD')
      expect(Number.isNaN(Number(offer.price))).toBe(false)
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

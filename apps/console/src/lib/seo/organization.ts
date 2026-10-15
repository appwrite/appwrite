import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { SEO_SITE_NAME } from '@/lib/seo/page-meta'

const ORGANIZATION_ID = `${MARKETING_SITE_ORIGIN}/#organization`

/** Official profiles, matching the footer's social links. */
const ORGANIZATION_PROFILES = [
  'https://github.com/appwrite',
  'https://x.com/appwrite',
  'https://youtube.com/@appwrite',
  'https://www.linkedin.com/company/appwrite/',
]

/** Appwrite as a schema.org `Organization`: site owner, publisher, and author. */
export function getOrganizationSchema() {
  return {
    '@type': 'Organization',
    '@id': ORGANIZATION_ID,
    name: SEO_SITE_NAME,
    url: MARKETING_SITE_ORIGIN,
    logo: {
      '@type': 'ImageObject',
      url: `${MARKETING_SITE_ORIGIN}/apple-touch-icon.png`,
    },
    sameAs: ORGANIZATION_PROFILES,
  }
}

/** Site node for the homepage graph; refers to the organization by `@id`. */
export function getWebSiteSchema() {
  return {
    '@type': 'WebSite',
    '@id': `${MARKETING_SITE_ORIGIN}/#website`,
    name: SEO_SITE_NAME,
    url: MARKETING_SITE_ORIGIN,
    publisher: { '@id': ORGANIZATION_ID },
  }
}

import { getEnglishCatalog } from '@/lib/i18n'
import {
  getMarketingHomeOgImage,
  getMarketingPageMetaTags,
  MARKETING_HOMEPAGE_TITLE,
} from '@/lib/marketing/route-meta'
import { getDefaultSiteOrigin } from '@/lib/marketing/site-origin'
import { NOINDEX_FOLLOW_ROBOTS_META } from '@/lib/seo/indexing'
import { stringifyJsonLd } from '@/lib/seo/json-ld'
import { getOrganizationSchema, getWebSiteSchema } from '@/lib/seo/organization'

// Route heads load with the route tree on every page, so they live apart from
// the homepage they describe.

/** Bump when replacing homepage hero dashboard screenshots so caches refetch. */
const HOME_HERO_IMAGE_CACHE_BUST = '20260729'
export const HOME_HERO_LIGHT_SRC = `/images/heroes/console-app-light.avif?v=${HOME_HERO_IMAGE_CACHE_BUST}`
export const HOME_HERO_DARK_SRC = `/images/heroes/console-app-dark.avif?v=${HOME_HERO_IMAGE_CACHE_BUST}`

/** `/` and `/home` render the same page; both point search engines at `/`. */
export function getHomePageHead() {
  const canonical = `${getDefaultSiteOrigin()}/`
  return {
    meta: [
      ...getMarketingPageMetaTags({
        pageName: 'Home',
        title: MARKETING_HOMEPAGE_TITLE,
        description: getEnglishCatalog().website.home.seoDescription,
        ogImage: getMarketingHomeOgImage(),
      }),
      { property: 'og:url', content: canonical },
    ],
    links: [
      { rel: 'canonical', href: canonical },
      {
        rel: 'preload',
        as: 'image',
        href: HOME_HERO_LIGHT_SRC,
        media: '(prefers-color-scheme: light)',
      },
      {
        rel: 'preload',
        as: 'image',
        href: HOME_HERO_DARK_SRC,
        media: '(prefers-color-scheme: dark)',
      },
    ],
    scripts: [
      {
        type: 'application/ld+json',
        children: stringifyJsonLd({
          '@context': 'https://schema.org',
          '@graph': [getOrganizationSchema(), getWebSiteSchema()],
        }),
      },
    ],
  }
}

/**
 * `/home1`: the factory hero. Same canonical as `/`, kept out of search, and
 * no hero screenshot preload because the Console sits lower on the page.
 */
export function getFactoryHomePageHead() {
  const head = getHomePageHead()
  return {
    ...head,
    meta: [...head.meta, NOINDEX_FOLLOW_ROBOTS_META],
    links: head.links.filter((link) => link.rel !== 'preload'),
  }
}

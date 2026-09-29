export const BLOG_POSTS_PER_PAGE = 12
export const BLOG_SECONDARY_FEATURED_COUNT = 3
export const BLOG_SECONDARY_LATEST_COUNT = 3
export const BLOG_CATEGORY_SPOTLIGHT_POST_COUNT = 3
export const BLOG_COVER_ASPECT_CLASS = 'aspect-[16/9]'

/** Never promote these slugs in blog index spotlights (hero, secondary, category blocks). */
export const BLOG_INDEX_SPOTLIGHT_EXCLUDED_SLUGS = new Set<string>([
  'introducing-appwrite-cloud-start-plan',
])

/**
 * Preferred featured order on the blog index (hero first, then secondary).
 * Posts not listed keep date order after these.
 */
export const BLOG_FEATURED_SLUG_ORDER = [
  'announcing-appwrite-2',
  'native-databases-all-appwrite-cloud-regions',
  'announcing-appwrite-chatgpt-plugin',
  'announcing-firewall-presets',
  'hyperloop-b',
  'appwrite-now-speaks-postgresql',
  'announcing-console-iv',
] as const

export const BLOG_SPOTLIGHT_CATEGORY_SLUGS = [
  'tutorials',
  'products',
  'customer-stories',
  'announcements',
  'security',
  'open-source',
  'ai',
  'startups',
  'devops',
  'architectures',
] as const

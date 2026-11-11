/**
 * A page's title and description, and the card it shows when shared (Open Graph
 * and Twitter), its image at an absolute address on the site as crawlers want.
 */

/** The site's public address. */
export const SITE = 'https://utopia-docs.appwrite.network'

export const NAME = 'Utopia for Rust'

/** The cover every page shares unless it has its own: the logo and the tagline (public/og.png, 1200 × 630). */
const COVER = { image: '/og.png', alt: 'Utopia: run at speed of Rust, one crate at a time.' }

/** `image` is a page's own card (public/og/…, from scripts/og.tsx); without one, the cover. */
export function share({ title, description, image = COVER.image, alt = COVER.alt }: { title?: string; description: string; image?: string; alt?: string }) {
  const full = title ? `${title} · ${NAME}` : NAME
  const url = `${SITE}${image}`
  return [
    { title: full },
    { name: 'description', content: description },
    { property: 'og:type', content: 'website' },
    { property: 'og:site_name', content: NAME },
    { property: 'og:title', content: full },
    { property: 'og:description', content: description },
    { property: 'og:image', content: url },
    { property: 'og:image:width', content: '1200' },
    { property: 'og:image:height', content: '630' },
    { property: 'og:image:alt', content: alt },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: full },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: url },
  ]
}

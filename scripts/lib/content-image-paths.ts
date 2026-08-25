import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))

export const VIBES_ROOT = resolve(__dirname, '../..')
export const WEBSITE_ROOT = resolve(VIBES_ROOT, '..', 'website')
export const WEBSITE_STATIC_IMAGES = join(WEBSITE_ROOT, 'static', 'images')
export const PUBLIC_IMAGES_ROOT = join(VIBES_ROOT, 'public', 'images')

/** Image trees copied from the website repo for docs, blog, changelog, author avatars, and integrations. */
export const CONTENT_IMAGE_SECTIONS = [
  'blog',
  'docs',
  'changelog',
  'avatars',
  'integrations',
] as const

export type ContentImageSection = (typeof CONTENT_IMAGE_SECTIONS)[number]

export function getPublicImagesDir(section: ContentImageSection): string {
  return join(PUBLIC_IMAGES_ROOT, section)
}

export function getWebsiteImagesDir(section: ContentImageSection): string {
  return join(WEBSITE_STATIC_IMAGES, section)
}

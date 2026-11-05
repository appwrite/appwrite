import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))

export const VIBES_ROOT = resolve(__dirname, '../..')
export const PUBLIC_IMAGES_ROOT = join(VIBES_ROOT, 'public', 'images')

/** Image trees under public/images for docs, blog, changelog, author avatars, and integrations. */
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

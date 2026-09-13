import { isCloudProfile } from '@/lib/console-profiles'

export function isStorageS3DocsSlug(slug: string): boolean {
  return slug === 'products/storage/s3' || slug.startsWith('products/storage/s3/')
}

export function isStorageS3DocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/storage/s3' ||
    normalized.startsWith('/docs/products/storage/s3/')
  )
}

export function isStorageS3DocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/storage/s3' ||
    path.startsWith('/docs/products/storage/s3/')
  )
}

export function isStorageS3DocsEnabled(): boolean {
  return isCloudProfile()
}

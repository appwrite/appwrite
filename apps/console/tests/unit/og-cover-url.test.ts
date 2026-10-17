import { describe, expect, test } from 'bun:test'
import { getChangelogEntryMetaTags, getChangelogEntrySchema } from '@/lib/changelog/seo'
import type { ChangelogEntry } from '@/lib/changelog/types'
import { getCoverImageDimensions } from '@/lib/seo/cover-dimensions'
import { buildOgCoverUrl } from '@/lib/seo/og-image'

const origin = 'https://appwrite.io'
const cover = '/images/blog/announcing-passkeys/cover.avif'
const jpeg = `${origin}/og/cover.jpg?path=%2Fimages%2Fblog%2Fannouncing-passkeys%2Fcover.avif`

function getContent(
  tags: Array<Record<string, string>>,
  key: string,
): string | undefined {
  return tags.find((tag) => tag.property === key || tag.name === key)?.content
}

describe('buildOgCoverUrl', () => {
  test('serves AVIF covers as JPEG', () => {
    expect(buildOgCoverUrl(cover, origin)).toBe(jpeg)
  })

  test('keeps covers X can render', () => {
    expect(buildOgCoverUrl('/images/blog/post/cover.png', origin)).toBe(
      `${origin}/images/blog/post/cover.png`,
    )
  })

  test('keeps absolute covers', () => {
    expect(buildOgCoverUrl('https://cdn.example.com/cover.avif', origin)).toBe(
      'https://cdn.example.com/cover.avif',
    )
  })
})

describe('changelog seo', () => {
  test('card image is not AVIF', () => {
    const entry = {
      title: 'Passkeys',
      description: 'Passkeys',
      cover,
      href: '/changelog/entry/2026-10-16',
      date: '2026-10-16',
    } as unknown as ChangelogEntry

    const tags = getChangelogEntryMetaTags(entry, {
      siteOrigin: origin,
    }) as unknown as Array<Record<string, string>>

    expect(getContent(tags, 'og:image')).toBe(jpeg)
    expect(getContent(tags, 'twitter:image')).toBe(jpeg)
    expect(getContent(tags, 'og:image:width')).toBe('1920')
  })

  test('structured data keeps the original cover', () => {
    const entry = {
      title: 'Passkeys',
      description: 'Passkeys',
      cover,
      href: '/changelog/entry/2026-10-16',
      date: '2026-10-16',
    } as unknown as ChangelogEntry

    expect(getChangelogEntrySchema(entry).image).toEqual([`${origin}${cover}`])
  })
})

describe('getCoverImageDimensions', () => {
  test('ignores inherited object keys', () => {
    expect(getCoverImageDimensions('constructor')).toBeUndefined()
    expect(getCoverImageDimensions('toString')).toBeUndefined()
  })
})

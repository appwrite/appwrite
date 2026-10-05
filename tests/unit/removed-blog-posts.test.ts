import { cpSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { afterEach, describe, expect, test } from 'bun:test'
import { isRemovedBlogPost, parseBlogFrontmatter } from '@/lib/blog/frontmatter'
import { BLOG_POST_MAP } from '@/lib/blog/generated/manifest'
import { REMOVED_BLOG_POST_SLUGS } from '@/lib/blog/generated/removed'
import { getBlogPrerenderPaths } from '@/lib/blog/prerender-paths'
import { getRemovedBlogPostRedirectTarget } from '@/lib/blog/removed-redirect'
import {
  findRemovedBlogPostLinks,
  readRemovedBlogPostSlugs,
} from '@/lib/blog/removed-posts'
import {
  findImagePaths,
  pruneRemovedBlogAssets,
} from '../../scripts/prune-removed-blog-assets'

const removedSlugs = new Set(['old-post', 'celebrating-1.5-contributors'])

describe('isRemovedBlogPost', () => {
  test('reads the removed frontmatter flag', () => {
    const { frontmatter } = parseBlogFrontmatter('---\ntitle: Old\nremoved: true\n---\nBody')
    expect(isRemovedBlogPost(frontmatter)).toBe(true)
    expect(isRemovedBlogPost({ removed: 'true' })).toBe(true)
    expect(isRemovedBlogPost({ removed: false })).toBe(false)
    expect(isRemovedBlogPost({ unlisted: true })).toBe(false)
  })
})

describe('findRemovedBlogPostLinks', () => {
  test('finds relative, absolute, and export links with line numbers', () => {
    const text = [
      'Intro [old](/blog/post/old-post) text.',
      'Keep [live](/blog/post/live-post).',
      '- [Abs](https://appwrite.io/blog/post/celebrating-1.5-contributors?ref=x)',
      'Export /blog/post/old-post.md and trailing /blog/post/old-post.',
    ].join('\n')

    expect(findRemovedBlogPostLinks(text, removedSlugs)).toEqual([
      { line: 1, slug: 'old-post' },
      { line: 3, slug: 'celebrating-1.5-contributors' },
      { line: 4, slug: 'old-post' },
      { line: 4, slug: 'old-post' },
    ])
  })

  test('does not match slugs that only share a prefix', () => {
    expect(findRemovedBlogPostLinks('/blog/post/old-post-2', removedSlugs)).toEqual([])
  })
})

describe('getRemovedBlogPostRedirectTarget', () => {
  const [removedSlug] = [...REMOVED_BLOG_POST_SLUGS]

  test('redirects removed posts and their markdown exports home', () => {
    expect(removedSlug).toBeDefined()
    expect(getRemovedBlogPostRedirectTarget(`/blog/post/${removedSlug}`)).toBe('/home')
    expect(getRemovedBlogPostRedirectTarget(`/blog/post/${removedSlug}/`)).toBe('/home')
    expect(getRemovedBlogPostRedirectTarget(`/blog/post/${removedSlug}.md`)).toBe('/home')
  })

  test('leaves live posts and other paths alone', () => {
    const [liveSlug] = Object.keys(BLOG_POST_MAP)
    expect(getRemovedBlogPostRedirectTarget(`/blog/post/${liveSlug}`)).toBeNull()
    expect(getRemovedBlogPostRedirectTarget(`/docs/${removedSlug}`)).toBeNull()
    expect(getRemovedBlogPostRedirectTarget('/blog')).toBeNull()
  })
})

describe('removed posts in generated output', () => {
  test('generated slug list matches the frontmatter flags', () => {
    expect([...REMOVED_BLOG_POST_SLUGS].sort()).toEqual(
      [...readRemovedBlogPostSlugs()].sort(),
    )
  })

  test('author pages are only prerendered for authors with remaining posts', () => {
    const posts = Object.values(BLOG_POST_MAP).filter((post) => !post.draft)
    const authorPaths = getBlogPrerenderPaths().filter((path) =>
      path.startsWith('/blog/author/'),
    )
    expect(authorPaths.length).toBeGreaterThan(0)
    for (const path of authorPaths) {
      const author = path.slice('/blog/author/'.length)
      expect(posts.some((post) => [post.author].flat().includes(author))).toBe(true)
    }
  })

  test('removed posts are not in the manifest or prerendered paths', () => {
    const prerenderPaths = new Set(getBlogPrerenderPaths())
    for (const slug of REMOVED_BLOG_POST_SLUGS) {
      expect(BLOG_POST_MAP[slug]).toBeUndefined()
      expect(prerenderPaths.has(`/blog/post/${slug}`)).toBe(false)
    }
  })
})

describe('findImagePaths', () => {
  test('extracts public image paths from markdoc and urls', () => {
    const text =
      'cover: /images/blog/old-post/cover.avif\n![x](https://appwrite.io/images/blog/shared.png?w=2) "/images/a/b.webp"'
    expect(findImagePaths(text)).toEqual([
      '/images/blog/old-post/cover.avif',
      '/images/blog/shared.png',
      '/images/a/b.webp',
    ])
  })
})

describe('pruneRemovedBlogAssets', () => {
  let root = ''

  afterEach(() => {
    if (root) rmSync(root, { recursive: true, force: true })
  })

  function writeImage(directory: string, imagePath: string) {
    const file = join(directory, imagePath)
    mkdirSync(dirname(file), { recursive: true })
    writeFileSync(file, 'image')
  }

  test('prunes removed-only images from the build output and never from public/', () => {
    root = mkdtempSync(join(tmpdir(), 'prune-removed-blog-assets-'))
    const publicDir = join(root, 'public')
    const clientDir = join(root, 'dist', 'client')
    const images = [
      'images/blog/old-post/cover.avif',
      'images/blog/old-post/inline/diagram.png',
      'images/blog/old-shared-cover.avif',
      'images/blog/live-post/cover.avif',
    ]
    for (const image of images) writeImage(publicDir, image)
    cpSync(publicDir, clientDir, { recursive: true })

    const { pruned, kept } = pruneRemovedBlogAssets({
      clientDir,
      publicDir,
      removedPosts: new Map([
        [
          'old-post',
          [
            'cover: /images/blog/old-shared-cover.avif',
            '![x](/images/blog/old-post/cover.avif)',
            '![y](/images/../../../escape.png)',
          ].join('\n'),
        ],
      ]),
      liveSources: [
        'cover: /images/blog/old-shared-cover.avif',
        '![z](/images/blog/live-post/cover.avif)',
      ],
    })

    expect(pruned).toEqual([
      '/images/blog/old-post/cover.avif',
      '/images/blog/old-post/inline/diagram.png',
    ])
    expect(kept).toEqual(['/images/blog/old-shared-cover.avif'])
    expect(existsSync(join(clientDir, 'images/blog/old-post'))).toBe(false)
    expect(existsSync(join(clientDir, 'images/blog/old-shared-cover.avif'))).toBe(true)
    expect(existsSync(join(clientDir, 'images/blog/live-post/cover.avif'))).toBe(true)
    for (const image of images) {
      expect(existsSync(join(publicDir, image))).toBe(true)
    }
  })
})

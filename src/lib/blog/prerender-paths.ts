import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { BLOG_POSTS_PER_PAGE } from './constants'
import { parseBlogFrontmatter } from './frontmatter'

const packageRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../..',
)

const postsDirectories = [
  path.join(packageRoot, 'src/content/blog/posts'),
  path.join(packageRoot, 'src/content/blog-local/posts'),
]
const categoriesDirectory = path.join(packageRoot, 'src/content/blog/categories')
const authorsDirectory = path.join(packageRoot, 'src/content/blog/authors')

function readSlugsFromDirectory(directory: string): string[] {
  if (!fs.existsSync(directory)) return []

  return fs
    .readdirSync(directory)
    .filter((filename) => filename.endsWith('.markdoc'))
    .map((filename) => filename.replace(/\.markdoc$/, ''))
    .sort()
}

function parseBoolean(value: unknown): boolean {
  if (typeof value === 'boolean') return value
  if (value === 'true') return true
  if (value === 'false') return false
  return false
}

function readPublicPostSlugsFromDirectory(directory: string): string[] {
  if (!fs.existsSync(directory)) return []

  return fs
    .readdirSync(directory)
    .filter((filename) => filename.endsWith('.markdoc'))
    .map((filename) => {
      const slug = filename.replace(/\.markdoc$/, '')
      const raw = fs.readFileSync(path.join(directory, filename), 'utf8')
      const { frontmatter } = parseBlogFrontmatter(raw)
      const draft = parseBoolean(frontmatter.draft)
      const unlisted = parseBoolean(frontmatter.unlisted)
      return draft || unlisted ? null : slug
    })
    .filter((slug): slug is string => slug != null)
    .sort()
}

function readBlogPathsFromClientDirectory(clientDirectory: string, segment: string): string[] {
  const directory = path.join(clientDirectory, 'blog', segment)
  if (!fs.existsSync(directory)) return []

  return fs
    .readdirSync(directory)
    .filter((filename) => filename.endsWith('.html'))
    .map((filename) => filename.replace(/\.html$/, ''))
    .sort()
    .map((slug) => `/blog/${segment}/${slug}`)
}

function getBlogPaginationPaths(publicPostCount: number): string[] {
  const totalPages = Math.ceil(publicPostCount / BLOG_POSTS_PER_PAGE)
  return Array.from({ length: Math.max(0, totalPages - 1) }, (_, index) =>
    `/blog/${index + 2}`,
  )
}

function getBlogIndexPaths(publicPostCount: number): string[] {
  return ['/blog', ...getBlogPaginationPaths(publicPostCount)]
}

function getBlogPostPathsFromSlugs(slugs: string[]): string[] {
  return slugs.map((slug) => `/blog/post/${slug}`)
}

function getBlogCategoryPathsFromSlugs(slugs: string[]): string[] {
  return slugs.map((slug) => `/blog/category/${slug}`)
}

function getBlogAuthorPathsFromSlugs(slugs: string[]): string[] {
  return slugs.map((slug) => `/blog/author/${slug}`)
}

/** Build-time paths from source markdoc files. */
export function getBlogPrerenderPaths(options?: {
  includePosts?: boolean
}): string[] {
  const includePosts = options?.includePosts ?? true
  const postSlugs = postsDirectories.flatMap((directory) =>
    readPublicPostSlugsFromDirectory(directory),
  )
  const uniquePostSlugs = [...new Set(postSlugs)].sort()
  const categorySlugs = readSlugsFromDirectory(categoriesDirectory)
  const authorSlugs = readSlugsFromDirectory(authorsDirectory)

  return [
    ...getBlogIndexPaths(uniquePostSlugs.length),
    ...(includePosts ? getBlogPostPathsFromSlugs(uniquePostSlugs) : []),
    ...getBlogCategoryPathsFromSlugs(categorySlugs),
    ...getBlogAuthorPathsFromSlugs(authorSlugs),
  ]
}

/** Runtime paths from prerendered client HTML output. */
export function getBlogPrerenderPathsFromClient(clientDirectory: string): string[] {
  const postSlugs = readBlogPathsFromClientDirectory(clientDirectory, 'post')
  const categorySlugs = readBlogPathsFromClientDirectory(clientDirectory, 'category')
  const authorSlugs = readBlogPathsFromClientDirectory(clientDirectory, 'author')

  const paginationPaths = fs.existsSync(path.join(clientDirectory, 'blog'))
    ? fs
        .readdirSync(path.join(clientDirectory, 'blog'))
        .filter((filename) => /^\d+\.html$/.test(filename))
        .map((filename) => `/blog/${filename.replace(/\.html$/, '')}`)
        .sort((a, b) => Number(a.split('/').pop()) - Number(b.split('/').pop()))
    : []

  return [
    '/blog',
    ...paginationPaths,
    ...postSlugs,
    ...categorySlugs,
    ...authorSlugs,
  ]
}

export function isBlogPrerenderPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (normalized === '/blog') return true
  if (/^\/blog\/\d+$/.test(normalized)) return true
  if (normalized.startsWith('/blog/post/')) return true
  if (normalized.startsWith('/blog/category/')) return true
  if (normalized.startsWith('/blog/author/')) return true
  return false
}

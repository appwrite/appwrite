import { extractDocsToc } from '@/lib/docs/toc'
import type { DocsTocItem } from '@/lib/docs/types'
import { BLOG_POSTS_PER_PAGE } from './constants'
import { preprocessBlogMarkdocContent } from './preprocess'
import {
  getFrontmatterAuthor,
  getFrontmatterDate,
  getFrontmatterFaqs,
  getFrontmatterString,
  parseBlogFrontmatter,
} from './frontmatter'
import type {
  BlogAuthor,
  BlogCategory,
  BlogPost,
  BlogPostMeta,
  BlogPostsPage,
} from './types'

const postLoaders = import.meta.glob('/src/content/blog/posts/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const categoryLoaders = import.meta.glob('/src/content/blog/categories/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const authorLoaders = import.meta.glob('/src/content/blog/authors/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromModulePath(modulePath: string, segment: string): string {
  const match = modulePath.match(
    new RegExp(`/src/content/blog/${segment}/(.+)\\.markdoc$`),
  )
  return match?.[1] ?? ''
}

export function normalizeCategory(value: string): string {
  return value.replace(/\s+/g, '-').toLowerCase()
}

function parseBoolean(value: unknown): boolean | undefined {
  if (typeof value === 'boolean') return value
  if (value === 'true') return true
  if (value === 'false') return false
  return undefined
}

function buildBlogPost(modulePath: string, raw: string): BlogPost {
  const slug = slugFromModulePath(modulePath, 'posts')
  const { frontmatter, body } = parseBlogFrontmatter(raw)
  const content = preprocessBlogMarkdocContent(body.trim())
  const date =
    getFrontmatterDate(frontmatter, 'date') ||
    getFrontmatterDate(frontmatter, 'lastUpdated')
  const lastUpdated =
    getFrontmatterDate(frontmatter, 'lastUpdated') || date
  const cover = getFrontmatterString(frontmatter, 'cover')
  const timeToReadRaw = frontmatter.timeToRead
  const timeToRead =
    typeof timeToReadRaw === 'number'
      ? timeToReadRaw
      : Number.parseInt(getFrontmatterString(frontmatter, 'timeToRead') ?? '0', 10) || 0

  return {
    slug,
    href: `/blog/post/${slug}`,
    title: getFrontmatterString(frontmatter, 'title') ?? slug,
    description: getFrontmatterString(frontmatter, 'description') ?? '',
    date,
    lastUpdated,
    timeToRead,
    author: getFrontmatterAuthor(frontmatter),
    category: getFrontmatterString(frontmatter, 'category') ?? '',
    featured: parseBoolean(frontmatter.featured),
    unlisted: parseBoolean(frontmatter.unlisted),
    draft: parseBoolean(frontmatter.draft),
    metaTitle: getFrontmatterString(frontmatter, 'metaTitle'),
    hasCover: Boolean(cover),
    content,
    faqs: getFrontmatterFaqs(frontmatter),
    toc: extractDocsToc(content),
  }
}

function buildBlogCategory(modulePath: string, raw: string): BlogCategory {
  const slug = slugFromModulePath(modulePath, 'categories')
  const { frontmatter } = parseBlogFrontmatter(raw)

  return {
    slug,
    name: getFrontmatterString(frontmatter, 'name') ?? slug,
    description: getFrontmatterString(frontmatter, 'description') ?? '',
    href: `/blog/category/${slug}`,
  }
}

function buildBlogAuthor(modulePath: string, raw: string): BlogAuthor {
  const slug = slugFromModulePath(modulePath, 'authors')
  const { frontmatter } = parseBlogFrontmatter(raw)
  const avatar = getFrontmatterString(frontmatter, 'avatar')

  return {
    slug,
    name: getFrontmatterString(frontmatter, 'name') ?? slug,
    role: getFrontmatterString(frontmatter, 'role') ?? '',
    bio: getFrontmatterString(frontmatter, 'bio') ?? '',
    hasAvatar: Boolean(avatar),
    twitter: getFrontmatterString(frontmatter, 'twitter'),
    linkedin: getFrontmatterString(frontmatter, 'linkedin'),
    github: getFrontmatterString(frontmatter, 'github'),
    href: `/blog/author/${slug}`,
  }
}

const allPosts = Object.entries(postLoaders)
  .map(([modulePath, raw]) => buildBlogPost(modulePath, raw))
  .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime())

const allCategories = Object.entries(categoryLoaders)
  .map(([modulePath, raw]) => buildBlogCategory(modulePath, raw))
  .sort((a, b) => a.name.localeCompare(b.name))

const allAuthors = Object.entries(authorLoaders)
  .map(([modulePath, raw]) => buildBlogAuthor(modulePath, raw))
  .sort((a, b) => a.name.localeCompare(b.name))

export const blogPostCount = allPosts.length

function isPublicPost(post: BlogPostMeta): boolean {
  return !post.draft && !post.unlisted
}

export function getPublicBlogPosts(): BlogPostMeta[] {
  return allPosts.filter(isPublicPost).map(toBlogPostMeta)
}

export function getAllBlogPosts(): BlogPost[] {
  return allPosts
}

export function getBlogPost(slug: string): BlogPost | null {
  const post = allPosts.find((entry) => entry.slug === slug) ?? null
  if (!post || post.draft) return null
  return post
}

export function getBlogAuthor(slug: string): BlogAuthor | null {
  return allAuthors.find((author) => author.slug === slug) ?? null
}

export function getBlogCategory(slug: string): BlogCategory | null {
  return allCategories.find((category) => category.slug === slug) ?? null
}

export function getAllBlogAuthors(): BlogAuthor[] {
  return allAuthors
}

export function getAllBlogCategories(): BlogCategory[] {
  return allCategories
}

export function getFilteredBlogCategories(): BlogCategory[] {
  const publicPosts = getPublicBlogPosts()
  return allCategories.filter((category) =>
    publicPosts.some((post) => postMatchesCategory(post, category.slug)),
  )
}

export function toBlogPostMeta(post: BlogPost | BlogPostMeta): BlogPostMeta {
  return {
    slug: post.slug,
    href: post.href,
    title: post.title,
    description: post.description,
    date: post.date,
    lastUpdated: post.lastUpdated,
    timeToRead: post.timeToRead,
    author: post.author,
    category: post.category,
    featured: post.featured,
    unlisted: post.unlisted,
    draft: post.draft,
    metaTitle: post.metaTitle,
    hasCover: post.hasCover,
  }
}

export function postMatchesCategory(post: BlogPostMeta, categorySlug: string): boolean {
  return normalizeCategory(post.category).includes(categorySlug)
}

export function postMatchesAuthor(post: BlogPostMeta, authorSlug: string): boolean {
  if (Array.isArray(post.author)) {
    return post.author.includes(authorSlug)
  }
  return post.author === authorSlug
}

export function getPostsForAuthor(authorSlug: string): BlogPostMeta[] {
  return getPublicBlogPosts().filter((post) => postMatchesAuthor(post, authorSlug))
}

export function getPostsForCategory(categorySlug: string): BlogPostMeta[] {
  return getPublicBlogPosts().filter((post) => postMatchesCategory(post, categorySlug))
}

export function generateBlogPageNavigation(
  currentPage: number,
  totalPages: number,
): number[] {
  const range: number[] = []
  const ellipseItem = -1
  const middlePages = 3
  const visiblePages = 5

  if (totalPages <= visiblePages + 2) {
    for (let i = 1; i <= totalPages; i++) {
      range.push(i)
    }
    return range
  }

  if (currentPage <= 4) {
    for (let i = 1; i <= visiblePages; i++) {
      range.push(i)
    }
    range.push(ellipseItem, totalPages)
    return range
  }

  if (currentPage > 4 && currentPage < totalPages - (visiblePages - 1)) {
    range.push(1, ellipseItem)
    for (
      let i = currentPage - Math.floor(middlePages / 2);
      i <= currentPage + Math.floor(middlePages / 2);
      i++
    ) {
      range.push(i)
    }
    range.push(ellipseItem, totalPages)
    return range
  }

  range.push(1, ellipseItem)
  for (let i = totalPages - (visiblePages - 1); i <= totalPages; i++) {
    range.push(i)
  }
  return range
}

export function getBlogPostsPage(options: {
  page?: number
  search?: string
  category?: string
}): BlogPostsPage {
  const currentPage = Math.max(1, options.page ?? 1)
  const searchQuery = options.search?.trim().toLowerCase() ?? ''
  const categoryQuery = options.category ? normalizeCategory(options.category) : ''

  let posts = getPublicBlogPosts()
  const featured =
    posts.find((post) => post.featured && !searchQuery && !categoryQuery) ?? null

  if (searchQuery || categoryQuery) {
    posts = posts.filter((post) => {
      const matchesSearch =
        !searchQuery || post.title.toLowerCase().includes(searchQuery)
      const matchesCategory =
        !categoryQuery || postMatchesCategory(post, categoryQuery)
      return matchesSearch && matchesCategory
    })
  }

  const totalPages = Math.max(1, Math.ceil(posts.length / BLOG_POSTS_PER_PAGE))
  const safePage = Math.min(currentPage, totalPages)
  const startIndex = (safePage - 1) * BLOG_POSTS_PER_PAGE
  const endIndex = safePage * BLOG_POSTS_PER_PAGE

  return {
    posts: posts.slice(startIndex, endIndex),
    featured,
    authors: allAuthors,
    categories: getFilteredBlogCategories(),
    currentPage: safePage,
    totalPages,
    navigation: generateBlogPageNavigation(safePage, totalPages),
  }
}


export function resolveBlogAuthors(
  authorSlugs: string | string[],
  authors: BlogAuthor[] = allAuthors,
): BlogAuthor[] {
  const slugs = Array.isArray(authorSlugs) ? authorSlugs : [authorSlugs]
  return slugs
    .map((slug) => authors.find((author) => author.slug === slug))
    .filter((author): author is BlogAuthor => author != null)
}

export function getRelatedBlogPosts(
  currentSlug: string,
  limit = 6,
): BlogPostMeta[] {
  return getPublicBlogPosts()
    .filter((post) => post.slug !== currentSlug)
    .slice(0, limit)
}

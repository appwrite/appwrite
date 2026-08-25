import { isFeatureEnabled } from '@/lib/console-profiles'
import { extractDocsToc } from '@/lib/docs/toc'
import type { DocsTocItem } from '@/lib/docs/types'
import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import {
  BLOG_CATEGORY_SPOTLIGHT_POST_COUNT,
  BLOG_POSTS_PER_PAGE,
  BLOG_SECONDARY_FEATURED_COUNT,
  BLOG_SPOTLIGHT_CATEGORY_SLUGS,
} from './constants'
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
  BlogCategorySpotlight,
  BlogPost,
  BlogPostMeta,
  BlogPostsPage,
} from './types'

const importedPostLoaders = import.meta.glob('/src/content/blog/posts/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const localPostLoaders = import.meta.glob('/src/content/blog-local/posts/*.markdoc', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const postLoaders = { ...importedPostLoaders, ...localPostLoaders }

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
    new RegExp(`/src/content/blog(?:-local)?/${segment}/(.+)\\.markdoc$`),
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
    cover,
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
    avatar,
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

/** Draft posts, newest first. Only surfaced when the blogDrafts flag is on. */
export function getDraftBlogPosts(): BlogPostMeta[] {
  return allPosts.filter((post) => post.draft).map(toBlogPostMeta)
}

export function getAllBlogPosts(): BlogPost[] {
  return allPosts
}

export function getBlogPost(slug: string): BlogPost | null {
  const post = allPosts.find((entry) => entry.slug === slug) ?? null
  if (!post) return null
  if (post.draft && !isFeatureEnabled('blogDrafts')) return null
  return post
}

export function getBlogMarkdownExport(slug: string): string | null {
  if (!getBlogPost(slug)) return null

  const modulePath = Object.keys(postLoaders).find(
    (path) => slugFromModulePath(path, 'posts') === slug,
  )
  if (!modulePath) return null
  const raw = postLoaders[modulePath]
  return raw ? markdocToMarkdown(raw) : null
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
    cover: post.cover,
    hasCover: post.hasCover,
  }
}

export function postMatchesCategory(post: BlogPostMeta, categorySlug: string): boolean {
  return normalizeCategory(post.category).includes(categorySlug)
}

export function getPrimaryPostCategorySlug(post: BlogPostMeta): string {
  const firstCategory = post.category.split(',')[0]?.trim() ?? ''
  return normalizeCategory(firstCategory)
}

export function getPostCategoryLabel(post: BlogPostMeta): string {
  const slug = getPrimaryPostCategorySlug(post)
  const category = getBlogCategory(slug)
  if (category) return category.name

  return slug
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
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

function buildBlogIndexSpotlights(posts: BlogPostMeta[]): {
  featured: BlogPostMeta | null
  secondaryFeatured: BlogPostMeta[]
  categorySpotlights: BlogCategorySpotlight[]
  excludedSlugs: Set<string>
} {
  const featuredPosts = posts.filter((post) => post.featured)
  const featured = featuredPosts[0] ?? null
  const excludedSlugs = new Set<string>()

  if (featured) {
    excludedSlugs.add(featured.slug)
  }

  const secondaryFeatured = featuredPosts.slice(1, 1 + BLOG_SECONDARY_FEATURED_COUNT)

  for (const post of secondaryFeatured) {
    excludedSlugs.add(post.slug)
  }

  if (secondaryFeatured.length < BLOG_SECONDARY_FEATURED_COUNT) {
    const fillers = posts
      .filter((post) => !excludedSlugs.has(post.slug))
      .slice(0, BLOG_SECONDARY_FEATURED_COUNT - secondaryFeatured.length)

    for (const post of fillers) {
      excludedSlugs.add(post.slug)
      secondaryFeatured.push(post)
    }
  }

  const categorySpotlights: BlogCategorySpotlight[] = []

  for (const slug of BLOG_SPOTLIGHT_CATEGORY_SLUGS) {
    const category = getBlogCategory(slug)
    if (!category) continue

    const categoryPosts = getPostsForCategory(slug)
      .filter((post) => !excludedSlugs.has(post.slug))
      .slice(0, BLOG_CATEGORY_SPOTLIGHT_POST_COUNT)

    if (categoryPosts.length === 0) continue

    for (const post of categoryPosts) {
      excludedSlugs.add(post.slug)
    }

    categorySpotlights.push({ category, posts: categoryPosts })
  }

  return {
    featured,
    secondaryFeatured,
    categorySpotlights,
    excludedSlugs,
  }
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
  const showSpotlights = currentPage === 1 && !searchQuery && !categoryQuery

  let featured: BlogPostMeta | null = null
  let secondaryFeatured: BlogPostMeta[] = []
  let categorySpotlights: BlogCategorySpotlight[] = []
  let excludedSlugs = new Set<string>()

  if (showSpotlights) {
    const spotlights = buildBlogIndexSpotlights(posts)
    featured = spotlights.featured
    secondaryFeatured = spotlights.secondaryFeatured
    categorySpotlights = spotlights.categorySpotlights
    excludedSlugs = spotlights.excludedSlugs
  }

  if (searchQuery || categoryQuery) {
    posts = posts.filter((post) => {
      const matchesSearch =
        !searchQuery || post.title.toLowerCase().includes(searchQuery)
      const matchesCategory =
        !categoryQuery || postMatchesCategory(post, categoryQuery)
      return matchesSearch && matchesCategory
    })
  }

  const listPosts = showSpotlights
    ? posts.filter((post) => !excludedSlugs.has(post.slug))
    : featured
      ? posts.filter((post) => post.slug !== featured.slug)
      : posts

  const totalPages = Math.max(1, Math.ceil(listPosts.length / BLOG_POSTS_PER_PAGE))
  const safePage = Math.min(currentPage, totalPages)
  const startIndex = (safePage - 1) * BLOG_POSTS_PER_PAGE
  const endIndex = safePage * BLOG_POSTS_PER_PAGE

  return {
    posts: listPosts.slice(startIndex, endIndex),
    featured,
    secondaryFeatured,
    categorySpotlights,
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

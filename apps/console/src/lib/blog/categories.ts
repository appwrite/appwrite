import { resolveCategorySlug } from './category-slugs'
import { getFrontmatterString, parseBlogFrontmatter } from './frontmatter'
import type { BlogCategory, BlogPostMeta } from './types'

// Categories live apart from the post manifest in `./content`: route heads
// (blog, docs, threads) need category names, and route heads load with the
// route tree on every page.

const categoryFiles = import.meta.glob(
  '/src/content/blog/categories/*.markdoc',
  {
    query: '?raw',
    import: 'default',
    eager: true,
  },
) as Record<string, string>

function buildBlogCategory(modulePath: string, raw: string): BlogCategory {
  const fileName = modulePath.slice(modulePath.lastIndexOf('/') + 1)
  const slug = resolveCategorySlug(fileName.slice(0, -'.markdoc'.length))
  const { frontmatter } = parseBlogFrontmatter(raw)

  return {
    slug,
    name: getFrontmatterString(frontmatter, 'name') ?? slug,
    description: getFrontmatterString(frontmatter, 'description') ?? '',
    href: `/blog/categories/${slug}`,
  }
}

const allCategories = Object.entries(categoryFiles)
  .map(([modulePath, raw]) => buildBlogCategory(modulePath, raw))
  .sort((a, b) => a.name.localeCompare(b.name))

export function getAllBlogCategories(): BlogCategory[] {
  return allCategories
}

export function getBlogCategory(slug: string): BlogCategory | null {
  const resolvedSlug = resolveCategorySlug(slug)
  return (
    allCategories.find((category) => category.slug === resolvedSlug) ?? null
  )
}

export function getPrimaryPostCategorySlug(post: BlogPostMeta): string {
  const firstCategory = post.category.split(',')[0]?.trim() ?? ''
  return resolveCategorySlug(firstCategory)
}

export function getPostCategoryLabel(post: BlogPostMeta): string {
  const slug = getPrimaryPostCategorySlug(post)
  const category = getBlogCategory(slug)
  if (category) return category.name

  return slug.replace(/-/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

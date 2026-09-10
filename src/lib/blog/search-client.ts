import type { BlogPostMeta } from './types'
import { BLOG_POSTS } from './generated/manifest'

export type BlogSearchResult = BlogPostMeta & {
  score: number
}

function getVisibleBlogPosts(): BlogPostMeta[] {
  return BLOG_POSTS.filter((post) => !post.draft && !post.unlisted)
}

export const BLOG_SEARCH_SUGGESTIONS = [
  'Authentication',
  'Databases',
  'Functions',
  'Self-hosting',
  'Tutorial',
] as const

export const BLOG_SEARCH_POST_COUNT = getVisibleBlogPosts().length

export function getBlogSearchPopularPosts(limit = 6): BlogPostMeta[] {
  return [...getVisibleBlogPosts()]
    .sort(
      (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
    )
    .slice(0, limit)
}

/**
 * Search blog posts by title, description, and category
 */
export function searchBlogPosts(query: string): BlogSearchResult[] {
  const trimmedQuery = query.trim()

  if (!trimmedQuery) {
    return []
  }

  const lowerQuery = trimmedQuery.toLowerCase()
  const results: BlogSearchResult[] = []

  for (const post of getVisibleBlogPosts()) {

    let score = 0
    const titleLower = post.title.toLowerCase()
    const descriptionLower = post.description.toLowerCase()
    const categoryLower = post.category.toLowerCase()

    // Exact title match (highest score)
    if (titleLower === lowerQuery) {
      score += 100
    }
    // Title starts with query
    else if (titleLower.startsWith(lowerQuery)) {
      score += 50
    }
    // Title contains query
    else if (titleLower.includes(lowerQuery)) {
      score += 30
    }

    // Description contains query
    if (descriptionLower.includes(lowerQuery)) {
      score += 15
    }

    // Category matches
    if (categoryLower.includes(lowerQuery)) {
      score += 10
    }

    if (score > 0) {
      results.push({
        ...post,
        score,
      })
    }
  }

  // Sort by score descending, then by date descending
  return results.sort((a, b) => {
    if (b.score !== a.score) {
      return b.score - a.score
    }
    return new Date(b.date).getTime() - new Date(a.date).getTime()
  })
}

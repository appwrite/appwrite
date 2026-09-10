import type { BlogPostMeta } from './types'
import { BLOG_POSTS } from './generated/manifest'

export type BlogSearchResult = BlogPostMeta & {
  score: number
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

  for (const post of BLOG_POSTS) {
    // Skip draft and unlisted posts
    if (post.draft || post.unlisted) {
      continue
    }

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

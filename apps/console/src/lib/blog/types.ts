import type { DocsTocItem } from '@/lib/docs/types'

export type BlogFaq = {
  question: string
  answer: string
}

export type BlogPostMeta = {
  slug: string
  href: string
  title: string
  description: string
  date: string
  lastUpdated: string
  timeToRead: number
  author: string | string[]
  category: string
  featured?: boolean
  unlisted?: boolean
  draft?: boolean
  metaTitle?: string
  cover?: string
  hasCover: boolean
}

export type BlogPost = BlogPostMeta & {
  content: string
  faqs?: BlogFaq[]
  toc: DocsTocItem[]
}

export type BlogAuthor = {
  slug: string
  name: string
  role: string
  bio: string
  avatar?: string
  hasAvatar: boolean
  twitter?: string
  linkedin?: string
  github?: string
  href: string
}

export type BlogCategory = {
  slug: string
  name: string
  description: string
  href: string
}

export type BlogCategorySpotlight = {
  category: BlogCategory
  posts: BlogPostMeta[]
}

export type BlogPostsPage = {
  posts: BlogPostMeta[]
  featured: BlogPostMeta | null
  secondaryFeatured: BlogPostMeta[]
  categorySpotlights: BlogCategorySpotlight[]
  authors: BlogAuthor[]
  categories: BlogCategory[]
  currentPage: number
  totalPages: number
  navigation: number[]
}

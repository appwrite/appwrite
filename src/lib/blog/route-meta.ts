import type { BlogAuthor, BlogCategory, BlogPost } from './types'
import type { BlogSeoOptions } from './seo'
import {
  getBlogAuthorMetaTags as buildBlogAuthorMetaTags,
  getBlogCategoryMetaTags as buildBlogCategoryMetaTags,
  getBlogIndexMetaTags as buildBlogIndexMetaTags,
  getBlogPostMetaTags as buildBlogPostMetaTags,
} from './seo'

type MetaTag = Record<string, string>

function asRouteMetaTags(tags: readonly MetaTag[]): MetaTag[] {
  return [...tags]
}

export function getBlogIndexRouteMetaTags(options?: BlogSeoOptions) {
  return asRouteMetaTags(buildBlogIndexMetaTags(options) as unknown as MetaTag[])
}

export function getBlogPostRouteMetaTags(
  post: BlogPost,
  authors: BlogAuthor[] = [],
  options?: BlogSeoOptions,
) {
  return asRouteMetaTags(
    buildBlogPostMetaTags(post, authors, options) as unknown as MetaTag[],
  )
}

export function getBlogCategoryRouteMetaTags(
  category: BlogCategory,
  options?: BlogSeoOptions,
) {
  return asRouteMetaTags(buildBlogCategoryMetaTags(category, options) as unknown as MetaTag[])
}

export function getBlogAuthorRouteMetaTags(author: BlogAuthor, options?: BlogSeoOptions) {
  return asRouteMetaTags(buildBlogAuthorMetaTags(author, options) as unknown as MetaTag[])
}

import type { BlogAuthor, BlogCategory, BlogPost } from './types'
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

export function getBlogIndexRouteMetaTags() {
  return asRouteMetaTags(buildBlogIndexMetaTags() as unknown as MetaTag[])
}

export function getBlogPostRouteMetaTags(post: BlogPost) {
  return asRouteMetaTags(buildBlogPostMetaTags(post) as unknown as MetaTag[])
}

export function getBlogCategoryRouteMetaTags(category: BlogCategory) {
  return asRouteMetaTags(buildBlogCategoryMetaTags(category) as unknown as MetaTag[])
}

export function getBlogAuthorRouteMetaTags(author: BlogAuthor) {
  return asRouteMetaTags(buildBlogAuthorMetaTags(author) as unknown as MetaTag[])
}

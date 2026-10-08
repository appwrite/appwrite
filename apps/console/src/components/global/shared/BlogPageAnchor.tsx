'use client'

import { Link } from '@tanstack/react-router'
import type { ComponentProps, ReactNode } from 'react'
import { useLocalMarketingEnabled } from '@/lib/marketing/local-marketing'
import {
  getBlogPageUrl,
  isBlogPageExternal,
  parseBlogPagePath,
} from '@/lib/marketing/urls'

type BlogPageAnchorProps = Omit<ComponentProps<'a'>, 'href' | 'children'> & {
  href: string
  children: ReactNode
}

function BlogRouterLink({
  blogPath,
  className,
  children,
  ...props
}: Omit<BlogPageAnchorProps, 'href'> & { blogPath: string }) {
  if (blogPath.startsWith('/blog/post/')) {
    const slug = blogPath.slice('/blog/post/'.length)
    return (
      <Link
        to="/blog/post/$slug"
        params={{ slug }}
        className={className}
        {...props}
      >
        {children}
      </Link>
    )
  }

  if (blogPath.startsWith('/blog/categories/')) {
    const category = blogPath.slice('/blog/categories/'.length)
    return (
      <Link
        to="/blog/categories/$category"
        params={{ category }}
        className={className}
        {...props}
      >
        {children}
      </Link>
    )
  }

  if (blogPath.startsWith('/blog/category/')) {
    const category = blogPath.slice('/blog/category/'.length)
    return (
      <Link
        to="/blog/categories/$category"
        params={{ category }}
        className={className}
        {...props}
      >
        {children}
      </Link>
    )
  }

  if (blogPath.startsWith('/blog/author/')) {
    const author = blogPath.slice('/blog/author/'.length)
    return (
      <Link
        to="/blog/author/$author"
        params={{ author }}
        className={className}
        {...props}
      >
        {children}
      </Link>
    )
  }

  const pageMatch = blogPath.match(/^\/blog\/(\d+)$/)
  if (pageMatch) {
    return (
      <Link
        to="/blog/$page"
        params={{ page: pageMatch[1] }}
        className={className}
        {...props}
      >
        {children}
      </Link>
    )
  }

  return (
    <Link to="/blog" className={className} {...props}>
      {children}
    </Link>
  )
}

export function BlogPageAnchor({
  href,
  children,
  className,
  ...props
}: BlogPageAnchorProps) {
  const marketingEnabled = useLocalMarketingEnabled()
  const blogPath = parseBlogPagePath(href)
  const url = getBlogPageUrl(href, marketingEnabled)
  const external = blogPath ? isBlogPageExternal(marketingEnabled) : url.startsWith('http')

  if (blogPath && !external) {
    return (
      <BlogRouterLink blogPath={blogPath} className={className} {...props}>
        {children}
      </BlogRouterLink>
    )
  }

  return (
    <a
      href={url}
      className={className}
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      {...props}
    >
      {children}
    </a>
  )
}

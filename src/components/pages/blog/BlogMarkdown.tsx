import { BlogMarkdownBody } from './BlogMarkdownBody'
import { renderBlogMarkdocHtml } from '@/lib/blog/render-blog-markdoc-html'

type BlogMarkdownProps = {
  html?: string
  content?: string
  className?: string
}

export function BlogMarkdown({ html, content, className }: BlogMarkdownProps) {
  const renderedHtml = html ?? (content ? renderBlogMarkdocHtml(content) : '')
  if (!renderedHtml) return null

  return <BlogMarkdownBody html={renderedHtml} className={className} />
}

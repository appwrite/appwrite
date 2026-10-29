import { Link } from '@tanstack/react-router'
import { BlogCover } from './BlogCoverPlaceholder'
import { BlogPostAuthors } from './_components/BlogPostAuthors'
import { formatDate } from '@/lib/date-utils'
import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { resolveBlogAuthors } from '@/lib/blog/content'
import { BLOG_INDEX_CARD_TITLE_LINES_CLASS } from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'

type BlogPostCardProps = {
  post: BlogPostMeta
  authors: BlogAuthor[]
  featured?: boolean
  showDescription?: boolean
  className?: string
}

export function BlogPostCard({
  post,
  authors,
  featured = false,
  showDescription = true,
  className,
}: BlogPostCardProps) {
  const postAuthors = resolveBlogAuthors(post.author, authors)

  return (
    <article className={cn('group flex h-full flex-col', className)}>
      <Link to="/blog/post/$slug" params={{ slug: post.slug }} className="block">
        <BlogCover title={post.title} cover={post.cover} />
      </Link>

      <div className="mt-4 flex flex-1 flex-col">
        <Link
          to="/blog/post/$slug"
          params={{ slug: post.slug }}
          className="block"
        >
          <h2
            className={cn(
              BLOG_INDEX_CARD_TITLE_LINES_CLASS,
              'font-aeonik-pro font-normal leading-snug text-foreground transition-colors group-hover:text-foreground/80',
              featured ? 'text-[24px]' : 'text-[18px]',
            )}
          >
            {post.title}
          </h2>
        </Link>

        <BlogPostAuthors authors={postAuthors} className="mt-2" />

        {showDescription ? (
          <p className="mt-2 line-clamp-3 flex-1 text-[13px] leading-relaxed text-muted-foreground">
            {post.description}
          </p>
        ) : null}

        <div className="mt-4 flex items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
          <time dateTime={post.date}>{formatDate(post.date)}</time>
          {post.timeToRead > 0 ? <span>{post.timeToRead} min read</span> : null}
        </div>
      </div>
    </article>
  )
}

import { Link } from '@tanstack/react-router'
import { BlogAvatarPlaceholder, BlogCoverPlaceholder } from './BlogCoverPlaceholder'
import { formatDate } from '@/lib/date-utils'
import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { resolveBlogAuthors } from '@/lib/blog/content'
import { cn } from '@/lib/utils'

type BlogPostCardProps = {
  post: BlogPostMeta
  authors: BlogAuthor[]
  featured?: boolean
  className?: string
}

export function BlogPostCard({
  post,
  authors,
  featured = false,
  className,
}: BlogPostCardProps) {
  const postAuthors = resolveBlogAuthors(post.author, authors)
  const primaryAuthor = postAuthors[0]

  return (
    <article className={cn('group flex h-full flex-col', className)}>
      <Link to="/blog/post/$slug" params={{ slug: post.slug }} className="block">
        <BlogCoverPlaceholder title={post.title} />
      </Link>

      <div className="mt-4 flex flex-1 flex-col">
        <div className="flex items-center gap-2">
          {primaryAuthor ? (
            <Link
              to="/blog/author/$author"
              params={{ author: primaryAuthor.slug }}
              className="inline-flex items-center gap-2"
            >
              <BlogAvatarPlaceholder name={primaryAuthor.name} />
              <span className="text-[12px] text-muted-foreground hover:text-foreground">
                {postAuthors.map((author) => author.name).join(', ')}
              </span>
            </Link>
          ) : null}
        </div>

        <Link
          to="/blog/post/$slug"
          params={{ slug: post.slug }}
          className="mt-3 block"
        >
          <h2
            className={cn(
              'font-aeonik-pro font-normal leading-snug text-foreground transition-colors group-hover:text-foreground/80',
              featured ? 'text-[24px]' : 'text-[18px]',
            )}
          >
            {post.title}
          </h2>
        </Link>

        <p className="mt-2 line-clamp-3 flex-1 text-[13px] leading-relaxed text-muted-foreground">
          {post.description}
        </p>

        <div className="mt-4 flex items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
          <time dateTime={post.date}>{formatDate(post.date)}</time>
          {post.timeToRead > 0 ? <span>{post.timeToRead} min read</span> : null}
        </div>
      </div>
    </article>
  )
}

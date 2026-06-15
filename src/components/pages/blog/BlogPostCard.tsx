import { Link } from '@tanstack/react-router'
import { BlogAvatar, BlogCover } from './BlogCoverPlaceholder'
import { formatDate } from '@/lib/date-utils'
import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { resolveBlogAuthors } from '@/lib/blog/content'
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
  const primaryAuthor = postAuthors[0]

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
              'font-aeonik-pro font-normal leading-snug text-foreground transition-colors group-hover:text-foreground/80',
              featured ? 'text-[24px]' : 'text-[18px]',
            )}
          >
            {post.title}
          </h2>
        </Link>

        {primaryAuthor ? (
          <Link
            to="/blog/author/$author"
            params={{ author: primaryAuthor.slug }}
            className="mt-2 inline-flex w-fit items-center gap-2"
          >
            <BlogAvatar name={primaryAuthor.name} avatar={primaryAuthor.avatar} />
            <span className="text-[12px] text-muted-foreground hover:text-foreground">
              {postAuthors.map((author) => author.name).join(', ')}
            </span>
          </Link>
        ) : null}

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

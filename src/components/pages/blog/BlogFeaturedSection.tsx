import { Link } from '@tanstack/react-router'
import { resolveBlogAuthors } from '@/lib/blog/content'
import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { BLOG_INDEX_CARD_TITLE_LINES_CLASS } from '@/lib/blog/prose-typography'
import { formatDate } from '@/lib/date-utils'
import { cn } from '@/lib/utils'
import { BlogCover } from './BlogCoverPlaceholder'
import { BlogPostAuthors } from './_components/BlogPostAuthors'

type BlogFeaturedSectionProps = {
  post: BlogPostMeta
  authors: BlogAuthor[]
}

export function BlogFeaturedSection({ post, authors }: BlogFeaturedSectionProps) {
  const postAuthors = resolveBlogAuthors(post.author, authors)

  return (
    <section className="border-b border-border bg-muted/15 py-10 sm:py-14">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Featured
        </p>

        <article className="group mt-6 grid grid-cols-1 gap-8 lg:grid-cols-2 lg:items-start lg:gap-12">
          <Link
            to="/blog/post/$slug"
            params={{ slug: post.slug }}
            className="block overflow-hidden rounded-xl transition-transform duration-300 group-hover:scale-[1.02]"
          >
            <BlogCover
              title={post.title}
              cover={post.cover}
              className="rounded-none border-0"
            />
          </Link>

          <div className="flex flex-col">
            <Link
              to="/blog/post/$slug"
              params={{ slug: post.slug }}
              className="block"
            >
              <h2
                className={cn(
                  BLOG_INDEX_CARD_TITLE_LINES_CLASS,
                  'font-aeonik-pro text-[24px] font-normal leading-[1.3] text-foreground transition-colors group-hover:text-foreground/80 sm:text-[28px]',
                )}
              >
                {post.title}
              </h2>
            </Link>

            <BlogPostAuthors authors={postAuthors} className="mt-3" />

            <p className="mt-3 text-[14px] leading-relaxed text-muted-foreground sm:text-[15px]">
              {post.description}
            </p>

            <div className="mt-5 flex items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
              <time dateTime={post.date}>{formatDate(post.date)}</time>
              {post.timeToRead > 0 ? <span>{post.timeToRead} min read</span> : null}
            </div>
          </div>
        </article>
      </div>
    </section>
  )
}

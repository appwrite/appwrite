import { Link } from '@tanstack/react-router'
import { ArrowUpRight } from 'lucide-react'
import { SectionDottedBackground } from '@/components/pages/home/HomeSoftLights'
import type { BlogCategorySpotlight, BlogPostMeta } from '@/lib/blog/types'
import { BLOG_CATEGORY_CARD_TITLE_CLASS, BLOG_INDEX_CARD_TITLE_LINES_CLASS } from '@/lib/blog/prose-typography'
import { formatDate } from '@/lib/date-utils'
import { cn } from '@/lib/utils'

type BlogCategorySpotlightsSectionProps = {
  spotlights: BlogCategorySpotlight[]
}

type BlogCategorySpotlightPostLinkProps = {
  post: BlogPostMeta
  className?: string
}

function BlogCategorySpotlightPostLink({
  post,
  className,
}: BlogCategorySpotlightPostLinkProps) {
  return (
    <Link
      to="/blog/post/$slug"
      params={{ slug: post.slug }}
      className={cn(
        'group relative block rounded-md px-3 py-2.5 pe-9 transition-colors hover:bg-muted/40',
        className,
      )}
    >
      <p className={cn(BLOG_INDEX_CARD_TITLE_LINES_CLASS, 'text-[13px] font-medium leading-snug text-foreground transition-colors group-hover:text-foreground/80')}>
        {post.title}
      </p>
      <p className="mt-1 text-[11px] text-muted-foreground">
        {formatDate(post.date)}
        {post.timeToRead > 0 ? ` · ${post.timeToRead} min read` : ''}
      </p>
      <ArrowUpRight
        className="absolute top-2.5 end-2.5 size-3.5 text-muted-foreground"
        aria-hidden
      />
    </Link>
  )
}

export function BlogCategorySpotlightsSection({
  spotlights,
}: BlogCategorySpotlightsSectionProps) {
  if (spotlights.length === 0) return null

  return (
    <section className="relative isolate border-b border-border py-10 sm:py-14">
      <SectionDottedBackground />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
        <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Explore by topic
        </p>

        <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {spotlights.map(({ category, posts }) => (
            <article
              key={category.slug}
              className="flex flex-col rounded-xl border border-border bg-card/45 p-4"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <Link
                    to="/blog/category/$category"
                    params={{ category: category.slug }}
                    className="group inline-block min-w-0"
                  >
                    <p
                      className={cn(
                        BLOG_CATEGORY_CARD_TITLE_CLASS,
                        'transition-colors group-hover:text-foreground/80',
                      )}
                    >
                      {category.name}
                    </p>
                  </Link>
                  <p className="mt-1 line-clamp-2 text-[12px] leading-relaxed text-muted-foreground">
                    {category.description}
                  </p>
                </div>

                <Link
                  to="/blog/category/$category"
                  params={{ category: category.slug }}
                  className="inline-flex shrink-0 items-center gap-1 text-[11px] font-medium uppercase tracking-[0.12em] text-muted-foreground transition-colors hover:text-foreground"
                >
                  All
                  <ArrowUpRight className="size-3" aria-hidden />
                </Link>
              </div>

              <div className="mt-3 flex flex-col border-t border-border pt-1">
                {posts.map((post) => (
                  <BlogCategorySpotlightPostLink key={post.slug} post={post} />
                ))}
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}

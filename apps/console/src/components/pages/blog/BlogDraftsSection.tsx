import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { BlogPostCard } from './BlogPostCard'

type BlogDraftsSectionProps = {
  posts: BlogPostMeta[]
  authors: BlogAuthor[]
}

export function BlogDraftsSection({ posts, authors }: BlogDraftsSectionProps) {
  if (posts.length === 0) return null

  return (
    <section className="border-b border-border py-10 sm:py-14">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
            Drafts
          </p>
          <span className="rounded-full border border-border px-2 py-0.5 text-[10px] font-medium uppercase tracking-[0.12em] text-muted-foreground">
            {posts.length} unpublished
          </span>
        </div>
        <p className="mt-2 text-[13px] text-muted-foreground">
          Posts with <code className="font-mono text-[12px]">draft: true</code>{' '}
          frontmatter. Visible because the blog drafts feature flag is on.
        </p>

        <div className="mt-6 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
          {posts.map((post) => (
            <div key={post.slug} className="relative">
              <span className="absolute start-3 top-3 z-[1] rounded-full bg-background/90 px-2 py-0.5 text-[10px] font-medium uppercase tracking-[0.12em] text-foreground shadow-sm">
                Draft
              </span>
              <BlogPostCard
                post={post}
                authors={authors}
                showDescription={false}
              />
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

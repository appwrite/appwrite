import type { BlogAuthor, BlogPostMeta } from '@/lib/blog/types'
import { BlogPostCard } from './BlogPostCard'

type BlogSecondaryFeaturedSectionProps = {
  posts: BlogPostMeta[]
  authors: BlogAuthor[]
}

export function BlogSecondaryFeaturedSection({
  posts,
  authors,
}: BlogSecondaryFeaturedSectionProps) {
  if (posts.length === 0) return null

  return (
    <section className="border-b border-border py-10 sm:py-14">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          More featured stories
        </p>
        <div className="mt-6 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
          {posts.map((post) => (
            <BlogPostCard
              key={post.slug}
              post={post}
              authors={authors}
              showDescription={false}
            />
          ))}
        </div>
      </div>
    </section>
  )
}

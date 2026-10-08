import { useMemo } from 'react'
import {
  MarketingCtaSection,
  MarketingCtaSignupButtons,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDraftBlogPosts } from '@/lib/blog/content'
import type { BlogPostsPage } from '@/lib/blog/types'
import { cn } from '@/lib/utils'
import { BlogCategorySpotlightsSection } from './BlogCategorySpotlightsSection'
import { BlogSearchSection } from './BlogSearchSection'
import { BlogDraftsSection } from './BlogDraftsSection'
import { BlogFeaturedSection } from './BlogFeaturedSection'
import { BlogPagination } from './BlogPagination'
import { BlogPostCard } from './BlogPostCard'
import { BlogSecondaryFeaturedSection } from './BlogSecondaryFeaturedSection'

type BlogSearch = {
  search?: string
  category?: string
}

type ViewProps = BlogPostsPage & {
  search?: BlogSearch
}

export function View({
  posts,
  featured,
  secondaryFeatured,
  categorySpotlights,
  authors,
  currentPage,
  totalPages,
  navigation,
  search,
}: ViewProps) {
  const { features } = useConsoleProfile()

  const showSpotlights =
    currentPage === 1 &&
    !search?.search &&
    (!search?.category || search.category === 'Latest')

  const showFeatured = showSpotlights && featured

  // Read straight from the content module instead of the loader payload: drafts
  // never reach the SSR/prerendered HTML while the flag is off, and a debug-menu
  // toggle shows them without a reload.
  const drafts = useMemo(
    () => (features.blogDrafts ? getDraftBlogPosts() : []),
    [features.blogDrafts],
  )

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        title="Blog"
        description="Product updates, engineering deep dives, tutorials, and customer stories from the Appwrite team."
        align="left"
      />

      {showFeatured ? (
        <BlogFeaturedSection post={featured} authors={authors} />
      ) : null}

      {showSpotlights && secondaryFeatured.length > 0 ? (
        <BlogSecondaryFeaturedSection posts={secondaryFeatured} authors={authors} />
      ) : null}

      {showSpotlights && features.blogDrafts ? (
        <BlogDraftsSection posts={drafts} authors={authors} />
      ) : null}

      <BlogSearchSection />

      {showSpotlights && categorySpotlights.length > 0 ? (
        <BlogCategorySpotlightsSection spotlights={categorySpotlights} />
      ) : null}

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          {showSpotlights ? (
            <div className="border-b border-border pb-10">
              <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
                All articles
              </h2>
              <p className="mt-2 text-[13px] text-muted-foreground">
                Browse the full archive.
              </p>
            </div>
          ) : null}

          {posts.length === 0 ? (
            <div className="py-16 text-center">
              <p className="text-[15px] font-medium text-foreground">No articles found</p>
            </div>
          ) : (
            <>
              <div
                className={cn(
                  'grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3',
                  showSpotlights && 'mt-10',
                )}
              >
                {posts.map((post) => (
                  <BlogPostCard
                    key={post.slug}
                    post={post}
                    authors={authors}
                    showDescription={false}
                  />
                ))}
              </div>

              <BlogPagination
                currentPage={currentPage}
                totalPages={totalPages}
                navigation={navigation}
              />
            </>
          )}
        </div>
      </section>

      <MarketingCtaSection title="Ready to build?">
        <MarketingCtaSignupButtons />
      </MarketingCtaSection>
    </div>
  )
}

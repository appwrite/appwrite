import { Link, useNavigate } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import {
  MarketingCtaSection,
  MarketingCtaSignupButtons,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDraftBlogPosts, normalizeCategory } from '@/lib/blog/content'
import { buildBlogRouteSearch } from '@/lib/blog/search'
import type { BlogPostsPage } from '@/lib/blog/types'
import { cn } from '@/lib/utils'
import { BlogCategorySpotlightsSection } from './BlogCategorySpotlightsSection'
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
  categories,
  currentPage,
  totalPages,
  navigation,
  search,
}: ViewProps) {
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const [query, setQuery] = useState(search?.search ?? '')
  const selectedCategory = search?.category ?? 'Latest'

  useEffect(() => {
    setQuery(search?.search ?? '')
  }, [search?.search])

  const handleSearch = () => {
    navigate({
      to: '/blog',
      search: () =>
        buildBlogRouteSearch({
          search: query,
          category: selectedCategory,
        }),
      replace: true,
    })
  }

  const handleCategoryChange = (category: string) => {
    navigate({
      to: '/blog',
      search: () =>
        buildBlogRouteSearch({
          search: query,
          category,
        }),
    })
  }

  const showSpotlights =
    currentPage === 1 &&
    !search?.search &&
    selectedCategory === 'Latest'

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


      {showSpotlights && categorySpotlights.length > 0 ? (
        <BlogCategorySpotlightsSection spotlights={categorySpotlights} />
      ) : null}

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          {showSpotlights ? (
            <div className="border-b border-border pb-8">
              <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
                All articles
              </h2>
              <p className="mt-2 text-[13px] text-muted-foreground">
                Browse the full archive or filter by topic.
              </p>
            </div>
          ) : null}

          <div className={cn(showSpotlights ? 'mt-8' : undefined, 'flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between')}>
            <div className="relative max-w-md flex-1">
              <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') handleSearch()
                }}
                placeholder="Search articles..."
                className="h-10 ps-9 text-[13px]"
              />
            </div>
            <Button size="sm" className="h-10 text-[13px]" onClick={handleSearch}>
              Search
            </Button>
          </div>

          <div className="mt-6 flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => handleCategoryChange('Latest')}
              className={cn(
                'rounded-full px-3 py-1.5 text-[12px] font-medium transition-colors',
                selectedCategory === 'Latest'
                  ? 'bg-foreground text-background'
                  : 'bg-muted/60 text-muted-foreground hover:text-foreground',
              )}
            >
              Latest
            </button>
            {categories.map((category) => {
              const isActive = normalizeCategory(selectedCategory) === category.slug

              return (
                <button
                  key={category.slug}
                  type="button"
                  onClick={() => handleCategoryChange(category.slug)}
                  className={cn(
                    'rounded-full px-3 py-1.5 text-[12px] font-medium transition-colors',
                    isActive
                      ? 'bg-foreground text-background'
                      : 'bg-muted/60 text-muted-foreground hover:text-foreground',
                  )}
                >
                  {category.name}
                </button>
              )
            })}
          </div>

          {posts.length === 0 ? (
            <div className="py-16 text-center">
              <p className="text-[15px] font-medium text-foreground">No articles found</p>
              <p className="mt-2 text-[13px] text-muted-foreground">
                Try adjusting your search or clearing filters.
              </p>
              <Button variant="outline" size="sm" className="mt-4 h-9 text-[13px]" asChild>
                <Link to="/blog" search={{}}>
                  Clear filters
                </Link>
              </Button>
            </div>
          ) : (
            <>
              <div className="mt-10 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
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
                search={search?.search}
                category={
                  selectedCategory !== 'Latest' ? selectedCategory : undefined
                }
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

import { Link, useNavigate } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import {
  MarketingCtaSection,
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { normalizeCategory } from '@/lib/blog/content'
import type { BlogPostsPage } from '@/lib/blog/types'
import { cn } from '@/lib/utils'
import { BlogPagination } from './BlogPagination'
import { BlogPostCard } from './BlogPostCard'

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
  authors,
  categories,
  currentPage,
  totalPages,
  navigation,
  search,
}: ViewProps) {
  const navigate = useNavigate()
  const [query, setQuery] = useState(search?.search ?? '')
  const selectedCategory = search?.category ?? 'Latest'

  useEffect(() => {
    setQuery(search?.search ?? '')
  }, [search?.search])

  const handleSearch = () => {
    navigate({
      to: currentPage <= 1 ? '/blog' : '/blog/$page',
      ...(currentPage > 1 ? { params: { page: String(currentPage) } } : {}),
      search: {
        search: query.trim() || undefined,
        category: selectedCategory !== 'Latest' ? selectedCategory : undefined,
      },
      replace: true,
    })
  }

  const handleCategoryChange = (category: string) => {
    navigate({
      to: '/blog',
      search: {
        search: query.trim() || undefined,
        category: category !== 'Latest' ? category : undefined,
      },
    })
  }

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        title="Blog"
        description="Product updates, engineering deep dives, tutorials, and customer stories from the Appwrite team."
        align="left"
      />

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="relative max-w-md flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') handleSearch()
                }}
                placeholder="Search articles"
                className="h-10 pl-9 text-[13px]"
              />
            </div>
            <Button size="sm" className="h-10 text-[13px]" onClick={handleSearch}>
              Search
            </Button>
          </div>

          <div className="mt-6 flex flex-wrap gap-2">
            {['Latest', ...categories.map((category) => category.name)].map((label) => {
              const value =
                label === 'Latest' ? 'Latest' : normalizeCategory(label)
              const isActive =
                label === 'Latest'
                  ? selectedCategory === 'Latest'
                  : normalizeCategory(selectedCategory) === value

              return (
                <button
                  key={label}
                  type="button"
                  onClick={() => handleCategoryChange(value)}
                  className={cn(
                    'rounded-full border px-3 py-1.5 text-[12px] font-medium transition-colors',
                    isActive
                      ? 'border-foreground bg-foreground text-background'
                      : 'border-border bg-background text-muted-foreground hover:text-foreground',
                  )}
                >
                  {label}
                </button>
              )
            })}
          </div>

          {featured && currentPage === 1 && !search?.search && selectedCategory === 'Latest' ? (
            <div className="mt-10 border-y border-border py-8">
              <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                Featured
              </p>
              <div className="mt-6">
                <BlogPostCard post={featured} authors={authors} featured />
              </div>
            </div>
          ) : null}

          {posts.length === 0 ? (
            <div className="py-16 text-center">
              <p className="text-[15px] font-medium text-foreground">No articles found</p>
              <p className="mt-2 text-[13px] text-muted-foreground">
                Try adjusting your search or clearing filters.
              </p>
              <Button variant="outline" size="sm" className="mt-4 h-9 text-[13px]" asChild>
                <Link to="/blog">Clear filters</Link>
              </Button>
            </div>
          ) : (
            <>
              <div className="mt-10 grid grid-cols-1 gap-10 md:grid-cols-2 xl:grid-cols-3">
                {posts.map((post) => (
                  <BlogPostCard key={post.slug} post={post} authors={authors} />
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
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Get started
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>
    </div>
  )
}

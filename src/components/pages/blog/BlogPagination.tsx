import { Link } from '@tanstack/react-router'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

type BlogPaginationProps = {
  currentPage: number
  totalPages: number
  navigation: number[]
  search?: string
  category?: string
  className?: string
}

function buildBlogPageHref(page: number, search?: string, category?: string): string {
  const params = new URLSearchParams()
  if (search) params.set('search', search)
  if (category) params.set('category', category)
  const query = params.toString()
  const base = page <= 1 ? '/blog' : `/blog/${page}`
  return query ? `${base}?${query}` : base
}

export function BlogPagination({
  currentPage,
  totalPages,
  navigation,
  search,
  category,
  className,
}: BlogPaginationProps) {
  if (totalPages <= 1) return null

  return (
    <nav
      aria-label="Blog pagination"
      className={cn('mt-12 flex items-center justify-center gap-1', className)}
    >
      <Button
        variant="outline"
        size="icon"
        className="size-9"
        disabled={currentPage <= 1}
        asChild={currentPage > 1}
      >
        {currentPage > 1 ? (
          <Link
            to={currentPage === 2 ? '/blog' : '/blog/$page'}
            {...(currentPage === 2
              ? { search: { search, category } }
              : {
                  params: { page: String(currentPage - 1) },
                  search: { search, category },
                })}
            aria-label="Previous page"
          >
            <ChevronLeft className="h-4 w-4" />
          </Link>
        ) : (
          <span aria-hidden>
            <ChevronLeft className="h-4 w-4" />
          </span>
        )}
      </Button>

      {navigation.map((item, index) =>
        item === -1 ? (
          <span key={`ellipsis-${index}`} className="px-2 text-[13px] text-muted-foreground">
            …
          </span>
        ) : (
          <Button
            key={item}
            variant={item === currentPage ? 'secondary' : 'ghost'}
            size="sm"
            className="h-9 min-w-9 px-3 text-[13px]"
            asChild={item !== currentPage}
          >
            {item === currentPage ? (
              <span>{item}</span>
            ) : (
              <Link
                to={item === 1 ? '/blog' : '/blog/$page'}
                {...(item === 1
                  ? { search: { search, category } }
                  : {
                      params: { page: String(item) },
                      search: { search, category },
                    })}
              >
                {item}
              </Link>
            )}
          </Button>
        ),
      )}

      <Button
        variant="outline"
        size="icon"
        className="size-9"
        disabled={currentPage >= totalPages}
        asChild={currentPage < totalPages}
      >
        {currentPage < totalPages ? (
          <Link
            to="/blog/$page"
            params={{ page: String(currentPage + 1) }}
            search={{ search, category }}
            aria-label="Next page"
          >
            <ChevronRight className="h-4 w-4" />
          </Link>
        ) : (
          <span aria-hidden>
            <ChevronRight className="h-4 w-4" />
          </span>
        )}
      </Button>
    </nav>
  )
}

export { buildBlogPageHref }

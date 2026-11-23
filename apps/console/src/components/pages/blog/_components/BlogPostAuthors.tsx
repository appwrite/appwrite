import { Link } from '@tanstack/react-router'
import type { BlogAuthor } from '@/lib/blog/types'
import { cn } from '@/lib/utils'
import { BlogAuthorStack } from '../BlogCoverPlaceholder'

type BlogPostAuthorsProps = {
  authors: BlogAuthor[]
  className?: string
}

export function BlogPostAuthors({ authors, className }: BlogPostAuthorsProps) {
  if (authors.length === 0) return null

  const names = (
    <span className="text-[12px] text-muted-foreground hover:text-foreground">
      {authors.map((author) => author.name).join(', ')}
    </span>
  )

  if (authors.length === 1) {
    return (
      <Link
        to="/blog/author/$author"
        params={{ author: authors[0].slug }}
        className={cn('inline-flex w-fit items-center gap-2', className)}
      >
        <BlogAuthorStack authors={authors} />
        {names}
      </Link>
    )
  }

  return (
    <div className={cn('inline-flex w-fit items-center gap-2', className)}>
      <BlogAuthorStack authors={authors} linked />
      {names}
    </div>
  )
}

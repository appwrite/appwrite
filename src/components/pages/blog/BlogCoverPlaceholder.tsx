import { Link } from '@tanstack/react-router'
import { BLOG_COVER_ASPECT_CLASS } from '@/lib/blog/constants'
import type { BlogAuthor } from '@/lib/blog/types'
import { cn } from '@/lib/utils'

type BlogCoverPlaceholderProps = {
  title: string
  className?: string
}

export function BlogCoverPlaceholder({ title, className }: BlogCoverPlaceholderProps) {
  return (
    <div
      className={cn(
        'flex w-full items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/40',
        className,
        BLOG_COVER_ASPECT_CLASS,
      )}
      aria-hidden
    >
      <span className="max-w-[80%] text-center text-[13px] font-medium text-muted-foreground">
        {title}
      </span>
    </div>
  )
}

type BlogCoverProps = {
  title: string
  cover?: string
  className?: string
}

export function BlogCover({ title, cover, className }: BlogCoverProps) {
  if (!cover) {
    return <BlogCoverPlaceholder title={title} className={className} />
  }

  return (
    <div
      className={cn(
        'relative w-full overflow-hidden rounded-xl border border-border bg-muted/40',
        className,
        BLOG_COVER_ASPECT_CLASS,
      )}
    >
      <img
        src={cover}
        alt=""
        loading="lazy"
        decoding="async"
        className="size-full object-cover"
      />
    </div>
  )
}

type BlogAvatarPlaceholderProps = {
  name: string
  className?: string
}

export function BlogAvatarPlaceholder({ name, className }: BlogAvatarPlaceholderProps) {
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')

  return (
    <div
      className={cn(
        'flex size-10 shrink-0 items-center justify-center rounded-full border border-border bg-muted text-[12px] font-semibold text-muted-foreground',
        className,
      )}
      aria-hidden
    >
      {initials || '?'}
    </div>
  )
}

type BlogAvatarProps = {
  name: string
  avatar?: string
  className?: string
}

export function BlogAvatar({ name, avatar, className }: BlogAvatarProps) {
  if (!avatar) {
    return <BlogAvatarPlaceholder name={name} className={className} />
  }

  return (
    <img
      src={avatar}
      alt=""
      loading="lazy"
      decoding="async"
      className={cn(
        'size-10 shrink-0 rounded-full border border-border bg-muted object-cover opacity-95',
        className,
      )}
    />
  )
}

type BlogAuthorStackProps = {
  authors: Pick<BlogAuthor, 'slug' | 'name' | 'avatar'>[]
  className?: string
  avatarClassName?: string
  linked?: boolean
}

export function BlogAuthorStack({
  authors,
  className,
  avatarClassName,
  linked = false,
}: BlogAuthorStackProps) {
  if (authors.length === 0) return null

  if (authors.length === 1) {
    const author = authors[0]
    const avatar = (
      <BlogAvatar name={author.name} avatar={author.avatar} className={avatarClassName} />
    )

    if (linked) {
      return (
        <Link
          to="/blog/author/$author"
          params={{ author: author.slug }}
          className={className}
          aria-label={author.name}
        >
          {avatar}
        </Link>
      )
    }

    return <div className={className}>{avatar}</div>
  }

  return (
    <div
      className={cn('flex -space-x-2', className)}
      aria-label={authors.map((author) => author.name).join(', ')}
    >
      {authors.map((author) => {
        const avatar = (
          <BlogAvatar
            name={author.name}
            avatar={author.avatar}
            className={cn('ring-2 ring-background', avatarClassName)}
          />
        )

        if (linked) {
          return (
            <Link
              key={author.slug}
              to="/blog/author/$author"
              params={{ author: author.slug }}
              className="transition-opacity hover:opacity-80"
              aria-label={author.name}
            >
              {avatar}
            </Link>
          )
        }

        return <span key={author.slug}>{avatar}</span>
      })}
    </div>
  )
}

import { BLOG_COVER_ASPECT_CLASS } from '@/lib/blog/constants'
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

import { Link } from '@tanstack/react-router'
import { Check, MessageSquare } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { getRelativeTimeString } from '@/components/global/shared/DateTooltip'
import {
  isThreadResolved,
  sanitizeThreadContent,
} from '@/lib/threads/content'
import type { DiscordThread } from '@/lib/threads/types'
import { cn } from '@/lib/utils'

type ThreadCardProps = {
  thread: DiscordThread
  query?: string
}

function highlightText(text: string, query?: string) {
  if (!query?.trim()) return text

  const terms = query.split(/\s+/).filter(Boolean)
  if (terms.length === 0) return text

  const pattern = new RegExp(
    `(${terms.map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
    'gi',
  )
  const parts = text.split(pattern)

  return parts.map((part, index) => {
    const isMatch = terms.some(
      (term) => part.toLowerCase() === term.toLowerCase(),
    )
    if (!isMatch) return part

    return (
      <mark
        key={`${part}-${index}`}
        className="rounded-sm bg-[var(--brand-cta)]/30 px-0.5 text-inherit"
      >
        {part}
      </mark>
    )
  })
}

export function ThreadCard({ thread, query = '' }: ThreadCardProps) {
  const resolved = isThreadResolved(thread)

  return (
    <Link
      to="/threads/$threadId"
      params={{ threadId: thread.discord_id }}
      className={cn(
        'block rounded-xl border border-border bg-card/50 p-5 transition-colors',
        'hover:bg-muted/30',
      )}
    >
      <div className="flex min-w-0 gap-2">
        <h3 className="min-w-0 flex-1 text-[15px] font-medium leading-snug text-foreground">
          {highlightText(thread.title, query)}
        </h3>
      </div>

      <p className="mt-2 whitespace-pre-line break-words text-[13px] leading-relaxed text-muted-foreground">
        {highlightText(sanitizeThreadContent(thread.content), query)}
      </p>

      <div className="mt-4 flex min-w-0 flex-wrap items-center justify-between gap-4">
        <ul className="flex min-w-0 flex-wrap gap-2">
          {resolved ? (
            <li>
              <Badge variant="success" className="gap-1 text-[10px]">
                <Check className="h-3 w-3" />
                Resolved
              </Badge>
            </li>
          ) : null}
          {(thread.tags ?? []).map((tag) => (
            <li key={tag} className="min-w-0">
              <Badge variant="secondary" className="max-w-full truncate text-[10px]">
                {tag}
              </Badge>
            </li>
          ))}
        </ul>

        <div className="flex shrink-0 items-center gap-3 text-[12px] text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <MessageSquare className="h-3.5 w-3.5" aria-hidden />
            {thread.message_count}
          </span>
          {thread.last_activity ? (
            <span>{getRelativeTimeString(thread.last_activity)}</span>
          ) : null}
        </div>
      </div>
    </Link>
  )
}

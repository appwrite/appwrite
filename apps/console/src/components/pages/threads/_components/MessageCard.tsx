import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { Heart } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import { cleanThreadRoleLabel } from '@/lib/threads/content'
import type { DiscordMessage, ThreadMentionLookup } from '@/lib/threads/types'
import { ThreadMarkdown } from './ThreadMarkdown'

type MessageCardProps = {
  message: DiscordMessage
  isOriginalPost?: boolean
  mentionLookup?: ThreadMentionLookup
  children?: ReactNode
}

export function MessageCard({
  message,
  isOriginalPost = false,
  mentionLookup,
  children,
}: MessageCardProps) {
  const role = cleanThreadRoleLabel(message.role)

  return (
    <article
      id={message.$id ? `message-${message.$id}` : undefined}
      className="overflow-hidden rounded-xl border border-border bg-card/70 shadow-sm shadow-black/5"
    >
      <div className="flex items-start justify-between gap-4 border-b border-border bg-muted/20 px-5 py-4">
        <div className="flex min-w-0 items-center gap-3">
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              {message.author_id ? (
                <Link
                  to="/threads/authors/$authorId"
                  params={{ authorId: message.author_id }}
                  className="truncate text-[13px] font-medium text-foreground hover:underline"
                >
                  {message.author}
                </Link>
              ) : (
                <span className="truncate text-[13px] font-medium text-foreground">
                  {message.author}
                </span>
              )}
              {isOriginalPost ? (
                <Badge variant="info" className="text-[10px]">
                  Original post
                </Badge>
              ) : null}
            </div>
            {role ? (
              <p className="mt-1 text-[12px] text-muted-foreground">
                {role}
              </p>
            ) : null}
          </div>
        </div>
        <DateTooltip
          date={message.timestamp}
          showFormattedDate
          className="shrink-0 text-[12px] text-muted-foreground"
        />
      </div>

      <div className="px-5 py-5">
        <ThreadMarkdown content={message.message} mentionLookup={mentionLookup} />

        {message.reaction_count ? (
          <div className="mt-4 inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/30 px-2.5 py-1 text-[12px] text-muted-foreground">
            <Heart className="h-3.5 w-3.5" aria-hidden />
            {message.reaction_count}
          </div>
        ) : null}

        {children}
      </div>
    </article>
  )
}

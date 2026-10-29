import { Link } from '@tanstack/react-router'
import {
  ArrowLeft,
  ArrowUp,
  CalendarDays,
  Check,
  ExternalLink,
  MessageSquare,
  Users,
} from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  getDiscordThreadUrl,
  isThreadResolved,
  sanitizeThreadContent,
} from '@/lib/threads/content'
import type { ThreadsDetailLoaderData } from '@/lib/threads/types'
import { MessageCard } from './_components/MessageCard'
import { ThreadShareActions } from './_components/ThreadShareActions'
import { ThreadSummary } from './_components/ThreadSummary'
import { ThreadsPreFooter } from './_components/ThreadsPreFooter'

type ThreadDetailViewProps = ThreadsDetailLoaderData

export function ThreadDetailView({
  thread,
  messages,
  related,
  mentionLookup,
}: ThreadDetailViewProps) {
  const resolved = isThreadResolved(thread)
  const discordLink = getDiscordThreadUrl(thread.discord_id)
  const replyCount = Math.max(0, messages.length - 1)
  const threadPath = `/threads/${thread.discord_id}`

  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border bg-[radial-gradient(circle_at_top_right,hsl(var(--muted))_0,transparent_34rem)] py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Button variant="ghost" size="sm" className="-ms-2 mb-6 h-8 px-2" asChild>
            <Link to="/threads">
              <ArrowLeft className="me-1.5 h-4 w-4" />
              Back
            </Link>
          </Button>

          <div className="flex flex-col gap-8 py-4 sm:py-8">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
              <div className="min-w-0">
                <div className="mb-5 flex flex-wrap items-center gap-2">
                  {resolved ? (
                    <Badge variant="success" className="gap-1 text-[10px]">
                      <Check className="h-3 w-3" />
                      Resolved
                    </Badge>
                  ) : null}
                  {(thread.tags ?? []).map((tag) => (
                    <Badge key={tag} variant="info" className="text-[10px]">
                      {tag}
                    </Badge>
                  ))}
                </div>
                <h1 className="max-w-5xl text-balance font-aeonik-pro text-[34px] font-normal leading-tight tracking-tight text-foreground sm:text-[48px]">
                  {thread.title}
                </h1>
                <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px] text-muted-foreground">
                  <span className="inline-flex items-center gap-1.5">
                    <CalendarDays className="h-4 w-4" aria-hidden />
                    <DateTooltip date={thread.$createdAt} showFormattedDate />
                  </span>
                  {thread.last_activity ? (
                    <span>
                      Last activity <DateTooltip date={thread.last_activity} />
                    </span>
                  ) : null}
                </div>
              </div>

              <div className="flex shrink-0 flex-wrap items-center gap-2">
                <ThreadShareActions path={threadPath} title={thread.title} />
                <Button variant="brandCta" className="shrink-0" asChild>
                  <a href={discordLink} target="_blank" rel="noopener noreferrer">
                    View on Discord
                    <ExternalLink className="ms-1.5 h-4 w-4" />
                  </a>
                </Button>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div className="rounded-xl border border-border bg-card/55 px-4 py-4 shadow-sm shadow-black/5">
                <p className="text-[12px] text-muted-foreground">Votes</p>
                <p className="mt-1 inline-flex items-center gap-1.5 font-aeonik-pro text-[22px] font-normal text-foreground">
                  <ArrowUp className="h-4 w-4 text-muted-foreground" />
                  {thread.vote_count}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-card/55 px-4 py-4 shadow-sm shadow-black/5">
                <p className="text-[12px] text-muted-foreground">Replies</p>
                <p className="mt-1 inline-flex items-center gap-1.5 font-aeonik-pro text-[22px] font-normal text-foreground">
                  <MessageSquare className="h-4 w-4 text-muted-foreground" />
                  {replyCount}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-card/55 px-4 py-4 shadow-sm shadow-black/5">
                <p className="text-[12px] text-muted-foreground">Participants</p>
                <p className="mt-1 inline-flex items-center gap-1.5 font-aeonik-pro text-[22px] font-normal text-foreground">
                  <Users className="h-4 w-4 text-muted-foreground" />
                  {thread.participant_count ?? 'Unknown'}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-card/55 px-4 py-4 shadow-sm shadow-black/5">
                <p className="text-[12px] text-muted-foreground">Messages</p>
                <p className="mt-1 font-aeonik-pro text-[22px] font-normal text-foreground">
                  {messages.length}
                </p>
              </div>
            </div>
          </div>

          <div className="mt-6 grid gap-8 border-t border-border pt-8 lg:grid-cols-[minmax(0,2fr)_minmax(280px,360px)] lg:gap-10">
            <div className="min-w-0 space-y-5">
              {messages.map((message, index) => (
                <MessageCard
                  key={message.$id ?? `${message.timestamp}-${index}`}
                  message={message}
                  isOriginalPost={index === 0}
                  mentionLookup={mentionLookup}
                >
                  {index === 0 && thread.tldr?.trim() ? (
                    <ThreadSummary summary={thread.tldr.trim()} />
                  ) : null}
                </MessageCard>
              ))}

              <div className="rounded-xl border border-border bg-card/70 p-5 shadow-sm shadow-black/5">
                <p className="text-[15px] font-medium text-foreground">Join the discussion</p>
                <p className="mt-2 text-[13px] text-muted-foreground">
                  Reply to this thread by joining our Discord.
                </p>
                <Button className="mt-4" variant="brandCta" asChild>
                  <a href={discordLink} target="_blank" rel="noopener noreferrer">
                    Reply on Discord
                    <ExternalLink className="ms-1.5 h-4 w-4" />
                  </a>
                </Button>
              </div>
            </div>

            <aside className="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start">
              <div className="rounded-xl border border-border bg-card/70 p-5 shadow-sm shadow-black/5">
                <h2 className="text-[13px] font-medium text-foreground">
                  Discussion summary
                </h2>
                <dl className="mt-4 space-y-3 text-[13px]">
                  <div className="flex items-center justify-between gap-4">
                    <dt className="text-muted-foreground">Status</dt>
                    <dd className="text-foreground">
                      {resolved ? 'Resolved' : 'Open'}
                    </dd>
                  </div>
                  <div className="flex items-center justify-between gap-4">
                    <dt className="text-muted-foreground">Replies</dt>
                    <dd className="text-foreground">{replyCount}</dd>
                  </div>
                  <div className="flex items-center justify-between gap-4">
                    <dt className="text-muted-foreground">Votes</dt>
                    <dd className="text-foreground">{thread.vote_count}</dd>
                  </div>
                  {thread.last_activity ? (
                    <div className="flex items-center justify-between gap-4">
                      <dt className="text-muted-foreground">Synced</dt>
                      <dd className="text-foreground">
                        <DateTooltip date={thread.last_activity} />
                      </dd>
                    </div>
                  ) : null}
                </dl>
                <div className="mt-5 border-t border-border pt-4">
                  <p className="mb-3 text-[12px] text-muted-foreground">Share</p>
                  <ThreadShareActions path={threadPath} title={thread.title} />
                </div>
              </div>

              {related.length > 0 ? (
                <div className="rounded-xl border border-border bg-card/70 p-5 shadow-sm shadow-black/5">
                  <h2 className="text-[13px] font-medium text-foreground">
                    Recommended threads
                  </h2>
                  <ul className="mt-4 space-y-4">
                    {related.map((item) => (
                      <li
                        key={item.$id}
                        className="border-b border-border pb-4 last:border-b-0 last:pb-0"
                      >
                        <Link
                          to="/threads/$threadId"
                          params={{ threadId: item.discord_id }}
                          className="group block"
                        >
                          <p className="line-clamp-2 text-[13px] font-medium leading-5 text-foreground group-hover:underline">
                            {item.title}
                          </p>
                          <p className="mt-2 line-clamp-3 whitespace-pre-line text-[12px] leading-5 text-muted-foreground">
                            {sanitizeThreadContent(item.content, 180)}
                          </p>
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </aside>
          </div>
        </div>
      </section>

      <ThreadsPreFooter />
    </div>
  )
}

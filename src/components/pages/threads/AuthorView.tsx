import { Link } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cleanThreadRoleLabel } from '@/lib/threads/content'
import type { ThreadsAuthorLoaderData } from '@/lib/threads/types'
import { ThreadCard } from './_components/ThreadCard'
import { ThreadsPreFooter } from './_components/ThreadsPreFooter'

type AuthorViewProps = ThreadsAuthorLoaderData

export function AuthorView({ author, threads, total }: AuthorViewProps) {
  const roles = Array.from(
    new Set((author.roles ?? []).map(cleanThreadRoleLabel).filter(Boolean)),
  )

  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Button variant="ghost" size="sm" className="-ms-2 mb-6 h-8 px-2" asChild>
            <Link to="/threads">
              <ArrowLeft className="me-1.5 h-4 w-4" />
              Back
            </Link>
          </Button>

          <Breadcrumb>
            <BreadcrumbList>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/threads" className="cursor-pointer">
                    Threads
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className="truncate font-aeonik-pro">
                  {author.display_name}
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 border-y border-border py-8">
            <div className="min-w-0">
              <h1 className="font-aeonik-pro text-[32px] font-normal leading-none tracking-tight text-foreground sm:text-[40px]">
                {author.display_name}
              </h1>
              <p className="mt-2 text-[13px] text-muted-foreground">
                @{author.username}
              </p>

              {roles.length ? (
                <ul className="mt-4 flex flex-wrap gap-2">
                  {roles.map((role) => (
                    <li key={role}>
                      <Badge variant="secondary" className="text-[10px]">
                        {role}
                      </Badge>
                    </li>
                  ))}
                </ul>
              ) : null}

              <ul className="mt-6 flex gap-8">
                <li className="flex flex-col gap-1">
                  <span className="font-aeonik-pro text-[22px] font-normal text-foreground">
                    {author.thread_count}
                  </span>
                  <span className="text-[12px] text-muted-foreground">Threads</span>
                </li>
                <li className="flex flex-col gap-1">
                  <span className="font-aeonik-pro text-[22px] font-normal text-foreground">
                    {author.reply_count}
                  </span>
                  <span className="text-[12px] text-muted-foreground">Replies</span>
                </li>
              </ul>
            </div>
          </header>

          <div className="mt-10 flex items-center gap-3">
            <h2 className="text-[11px] font-semibold uppercase tracking-[0.25em] text-muted-foreground">
              Threads
            </h2>
            {total > threads.length ? (
              <span className="text-[12px] text-muted-foreground">
                Showing {threads.length} of {total}
              </span>
            ) : null}
          </div>

          <div className="mt-6 flex flex-col gap-4">
            {threads.length > 0 ? (
              threads.map((thread) => (
                <ThreadCard key={thread.$id} thread={thread} />
              ))
            ) : (
              <p className="text-[13px] text-muted-foreground">No threads yet.</p>
            )}
          </div>
        </div>
      </section>

      <ThreadsPreFooter />
    </div>
  )
}

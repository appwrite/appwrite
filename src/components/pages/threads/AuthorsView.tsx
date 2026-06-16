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
import { Button } from '@/components/ui/button'
import type { ThreadsAuthorsLoaderData } from '@/lib/threads/types'
import { ThreadsPreFooter } from './_components/ThreadsPreFooter'

type AuthorsViewProps = ThreadsAuthorsLoaderData

export function AuthorsView({ authors, total }: AuthorsViewProps) {
  return (
    <div className="relative overflow-x-hidden bg-background">
      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Button variant="ghost" size="sm" className="-ml-2 mb-6 h-8 px-2" asChild>
            <Link to="/threads">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
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
                  Authors
                </BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <header className="mt-8 border-y border-border py-8">
            <h1 className="font-aeonik-pro text-[32px] font-normal leading-none tracking-tight text-foreground sm:text-[40px]">
              Thread authors
            </h1>
            <p className="mt-3 max-w-2xl text-[14px] leading-6 text-muted-foreground">
              Browse Appwrite Discord community members who started discussions and
              replied across Threads.
            </p>
            <p className="mt-4 text-[13px] text-muted-foreground">
              {total} authors
            </p>
          </header>

          <div className="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {authors.map((author) => (
              <Link
                key={author.discord_id}
                to="/threads/authors/$authorId"
                params={{ authorId: author.discord_id }}
                className="rounded-xl border border-border bg-card/50 p-5 transition-colors hover:bg-muted/30"
              >
                <div className="min-w-0">
                  <h2 className="truncate text-[15px] font-medium text-foreground">
                    {author.display_name}
                  </h2>
                  <p className="mt-1 truncate text-[12px] text-muted-foreground">
                    @{author.username}
                  </p>
                  <div className="mt-4 flex gap-5 text-[12px] text-muted-foreground">
                    <span>
                      <strong className="font-medium text-foreground">
                        {author.thread_count}
                      </strong>{' '}
                      threads
                    </span>
                    <span>
                      <strong className="font-medium text-foreground">
                        {author.reply_count}
                      </strong>{' '}
                      replies
                    </span>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      <ThreadsPreFooter />
    </div>
  )
}

import type { ReactNode } from 'react'
import { AppwriteLogo, GitHubMark } from './Logo'
import { Time } from './Time'
import { commitUrl, type Index } from '@/lib/docs'
import { absolute } from '@/lib/time'

/** One field of the footer's strip: a label in mono capitals over its value. */
function Field({ label, children, led }: { label: string; children: ReactNode; led?: boolean }) {
  return (
    <div className="grid min-w-0 content-start gap-1.5 lg:border-l lg:border-border lg:pl-6">
      <span className="label flex items-center gap-2 text-muted-foreground">
        {led ? <span className="marker" aria-hidden /> : null}
        {label}
      </span>
      <span className="min-w-0 truncate font-mono text-[12px] text-foreground">{children}</span>
    </div>
  )
}

/**
 * The site's footer, set as the strip along the bottom of a datasheet: who
 * makes it, then the build these pages were generated from and the license
 * the crates are published under, each a label over its value.
 */
export function Footer({ index }: { index: Index }) {
  const link = 'transition-colors hover:text-highlight'
  return (
    <footer className="trace-rule mt-24 border-t border-border pt-7">
      <div className="grid gap-x-6 gap-y-6 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))]">
        <div className="grid content-start gap-2.5">
          <a href="https://appwrite.io" target="_blank" rel="noopener" className="w-fit text-foreground" aria-label="Appwrite">
            <svg viewBox="0 0 131 25" className="h-[18px] w-auto" aria-hidden>
              <AppwriteLogo />
            </svg>
          </a>
          <span className="text-[12.5px] text-muted-foreground">© {new Date().getFullYear()} Appwrite. All rights reserved.</span>
        </div>
        <Field label="Build" led>
          {index.commit ? (
            <a href={commitUrl(index.commit)} target="_blank" rel="noopener" className={link} title={index.commit_date ? `Committed ${absolute(index.commit_date)}` : undefined}>
              {index.commit}
            </a>
          ) : null}
          {index.commit && index.generated_at ? <span className="text-muted-foreground"> · </span> : null}
          {index.generated_at ? <Time date={index.generated_at} className="text-muted-foreground" /> : null}
        </Field>
        {index.branch ? <Field label="Branch">{index.branch}</Field> : null}
        <Field label="License">
          <a href="https://github.com/utopia-rs" target="_blank" rel="noopener" className={`inline-flex items-center gap-1.5 ${link}`}>
            <GitHubMark className="size-3" />
            MIT · utopia-rs
          </a>
        </Field>
      </div>
    </footer>
  )
}

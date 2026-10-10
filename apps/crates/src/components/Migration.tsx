import { ArrowLeftRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Markdown } from './Markdown'
import { useMigration } from '@/lib/prefs'

/**
 * Everything about PHP and the conversion lives in these cards, so the docs
 * read as plain Rust documentation until migration mode is on.
 */
export function MigrationCard({ title, children, className = '', id }: { title?: ReactNode; children: ReactNode; className?: string; id?: string }) {
  const on = useMigration()
  if (!on) return null
  return (
    <div id={id} className={`grid min-w-0 scroll-mt-20 gap-3 rounded-lg border border-migration-border bg-migration p-4 ${className}`}>
      <div className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-migration-ink uppercase">
        <ArrowLeftRight className="size-3.5" />
        Migration{title ? <span className="tracking-normal normal-case">· {title}</span> : null}
      </div>
      {children}
    </div>
  )
}

/** Inline migration details (badges, counts), shown only in migration mode. */
export function MigrationOnly({ children }: { children: ReactNode }) {
  return useMigration() ? <>{children}</> : null
}

/** PHP notes moved out of a doc comment: what changed from the PHP library. */
export function PhpNotes({ notes, title = 'PHP notes' }: { notes?: string | null; title?: string }) {
  if (!notes?.trim()) return null
  return (
    <MigrationCard title={title}>
      <Markdown text={notes} />
    </MigrationCard>
  )
}

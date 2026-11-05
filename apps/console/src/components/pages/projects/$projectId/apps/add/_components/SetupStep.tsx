import type { ReactNode } from 'react'

type Props = {
  number: number
  label: string
  children: ReactNode
}

/**
 * Numbered step row used inside the manual setup card. Provides a consistent
 * start-aligned number badge, an uppercase caption, and an indented content
 * column so every step in the sequence shares the same vertical rhythm.
 */
export function SetupStep({ number, label, children }: Props) {
  return (
    <div className="flex gap-3 sm:gap-4">
      <span
        className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-border bg-background text-[11px] font-semibold text-muted-foreground"
        aria-hidden
      >
        {number}
      </span>
      <div className="min-w-0 flex-1 pt-0.5">
        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          {label}
        </p>
        <div className="space-y-2">{children}</div>
      </div>
    </div>
  )
}

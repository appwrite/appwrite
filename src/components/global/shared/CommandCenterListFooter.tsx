'use client'

import { Keyboard } from 'lucide-react'

type CommandCenterListFooterProps = {
  onOpenShortcuts?: () => void
}

export function CommandCenterListFooter({
  onOpenShortcuts,
}: CommandCenterListFooterProps) {
  return (
    <div className="flex shrink-0 items-center justify-between border-t border-border px-3 py-2">
      <div className="flex items-center gap-3 text-[11px] text-muted-foreground/60">
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">↑↓</kbd>
          navigate
        </span>
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">↵</kbd>
          select
        </span>
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">esc</kbd>
          close
        </span>
      </div>
      {onOpenShortcuts ? (
        <button
          type="button"
          onClick={onOpenShortcuts}
          className="flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
        >
          <Keyboard className="h-3 w-3" />
          <span>All shortcuts</span>
        </button>
      ) : null}
    </div>
  )
}

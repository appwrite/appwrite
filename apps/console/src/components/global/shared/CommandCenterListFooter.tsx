'use client'

import { Keyboard } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

type CommandCenterListFooterProps = {
  onOpenShortcuts?: () => void
}

export function CommandCenterListFooter({
  onOpenShortcuts,
}: CommandCenterListFooterProps) {
  const t = useT()
  return (
    <div className="flex shrink-0 items-center justify-between border-t border-border px-3 py-2">
      <div className="flex items-center gap-3 text-[11px] text-muted-foreground/60">
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">↑↓</kbd>
          {t('navigate')}
        </span>
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">↵</kbd>
          {t('select')}
        </span>
        <span className="flex items-center gap-1">
          <kbd className="rounded bg-accent px-1 py-0.5 text-[10px]">esc</kbd>
          {t('close')}
        </span>
      </div>
      {onOpenShortcuts ? (
        <button
          type="button"
          onClick={onOpenShortcuts}
          className="flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
        >
          <Keyboard className="h-3 w-3" />
          <span>{t('All shortcuts')}</span>
        </button>
      ) : null}
    </div>
  )
}

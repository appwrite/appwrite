import { cn } from '@/lib/utils'

/** Filled shift arrow — Unicode ⇧ is too thin/small next to ⌘/⌥ in most fonts. */
function ShiftGlyph({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 12 12"
      className={cn('inline-block size-[0.9em] shrink-0', className)}
      fill="currentColor"
      aria-hidden
    >
      <path d="M6 1.1 11.2 6.8H8.35V10.9H3.65V6.8H0.8L6 1.1Z" />
    </svg>
  )
}

export function ShortcutGlyph({
  keyLabel,
  className,
}: {
  keyLabel: string
  className?: string
}) {
  if (keyLabel === '⇧') {
    return <ShiftGlyph className={className} />
  }
  return <span className={className}>{keyLabel}</span>
}

/** Inline shortcut sequence (e.g. ⌘⇧N) with correctly weighted glyphs. */
export function ShortcutGlyphs({
  keys,
  className,
}: {
  keys: string[]
  className?: string
}) {
  return (
    <span
      dir="ltr"
      className={cn('inline-flex items-center gap-px', className)}
    >
      {keys.map((key, i) => (
        <ShortcutGlyph key={`${key}-${i}`} keyLabel={key} />
      ))}
    </span>
  )
}

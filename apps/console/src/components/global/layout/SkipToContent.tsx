import { useT } from '@/lib/i18n/translate'

/**
 * Skip to content link for keyboard and screen reader users.
 * Positioned off-screen until focused, then slides into view to jump
 * past navigation to the main content.
 */
export function SkipToContent() {
  const t = useT()
  return (
    <a
      href="#main-content"
      className="fixed start-4 top-4 z-[110] -translate-y-full rounded-lg border border-border bg-background px-4 py-2.5 text-[13px] font-medium text-foreground opacity-0 shadow-lg ring-1 ring-border/50 transition-[opacity,transform] duration-200 ease-out focus:translate-y-0 focus:rounded-xl focus:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-background"
    >
      {t('Skip to content')}
    </a>
  )
}

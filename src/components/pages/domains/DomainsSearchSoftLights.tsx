import { cn } from '@/lib/utils'

type DomainsSearchBackgroundProps = {
  className?: string
}

/**
 * Dotted grid with static hero lights for the domains marketing search page.
 */
export function DomainsSearchBackground({
  className,
}: DomainsSearchBackgroundProps) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-0 overflow-hidden',
        className,
      )}
      aria-hidden
    >
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />

      <div className="absolute inset-0 z-[1] overflow-hidden">
        <div
          className={cn(
            'absolute -start-[42%] bottom-[-32%] h-[480px] w-[820px] opacity-[0.52]',
            'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.2)_0%,rgba(253,54,110,0.07)_38%,transparent_72%)]',
            'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.12)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
            'sm:-start-[38%] sm:h-[560px] sm:w-[980px]',
            'lg:-start-[36%] lg:h-[640px] lg:w-[1120px]',
          )}
        />
        <div
          className={cn(
            'absolute -end-[44%] bottom-[-34%] h-[500px] w-[840px] opacity-[0.52]',
            'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.17)_0%,rgba(124,103,254,0.06)_40%,transparent_74%)]',
            'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.04)_40%,transparent_74%)]',
            'sm:-end-[40%] sm:h-[580px] sm:w-[1000px]',
            'lg:-end-[38%] lg:h-[660px] lg:w-[1140px]',
          )}
        />
      </div>
    </div>
  )
}

import { cn } from '@/lib/utils'

/**
 * Soft ambient gradients without CSS blur filters — large blurs repaint the full
 * viewport on scroll and cause severe jank on the marketing home page.
 */
const variants = {
  hero: {
    left: cn(
      'absolute -left-[42%] bottom-[-32%] h-[480px] w-[820px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.2)_0%,rgba(253,54,110,0.07)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.12)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
      'sm:-left-[38%] sm:h-[560px] sm:w-[980px]',
      'lg:-left-[36%] lg:h-[640px] lg:w-[1120px]',
    ),
    right: cn(
      'absolute -right-[44%] bottom-[-34%] h-[500px] w-[840px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.17)_0%,rgba(124,103,254,0.06)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(99,102,241,0.11)_0%,rgba(99,102,241,0.04)_40%,transparent_74%)]',
      'sm:-right-[40%] sm:h-[580px] sm:w-[1000px]',
      'lg:-right-[38%] lg:h-[660px] lg:w-[1140px]',
    ),
  },
  testimonials: {
    left: cn(
      'absolute -left-[44%] bottom-[-34%] h-[500px] w-[860px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.18)_0%,rgba(253,54,110,0.06)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.11)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
      'sm:-left-[40%] sm:h-[580px] sm:w-[1020px]',
      'lg:-left-[38%] lg:h-[640px] lg:w-[1160px]',
    ),
    right: cn(
      'absolute -right-[44%] bottom-[-34%] h-[520px] w-[880px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.16)_0%,rgba(124,103,254,0.055)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(99,102,241,0.1)_0%,rgba(99,102,241,0.035)_40%,transparent_74%)]',
      'sm:-right-[40%] sm:h-[600px] sm:w-[1040px]',
      'lg:-right-[38%] lg:h-[660px] lg:w-[1180px]',
    ),
  },
} as const

type HomeSoftLightsProps = {
  variant?: keyof typeof variants
  className?: string
}

export function HomeSoftLights({
  variant = 'hero',
  className,
}: HomeSoftLightsProps) {
  const lights = variants[variant]

  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-y-0 left-1/2 z-0 w-screen -translate-x-1/2 overflow-hidden',
        className,
      )}
      aria-hidden
    >
      <div className={lights.left} />
      <div className={lights.right} />
    </div>
  )
}

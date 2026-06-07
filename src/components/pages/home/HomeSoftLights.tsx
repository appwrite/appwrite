import { cn } from '@/lib/utils'

/**
 * Soft ambient gradients without CSS blur filters — large blurs repaint the full
 * viewport on scroll and cause severe jank on the marketing home page.
 *
 * Blue/purple page wash uses #7C67FE (rgb 124, 103, 254) in light and dark mode.
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
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.04)_40%,transparent_74%)]',
      'sm:-right-[40%] sm:h-[580px] sm:w-[1000px]',
      'lg:-right-[38%] lg:h-[660px] lg:w-[1140px]',
    ),
  },
  pricing: {
    left: cn(
      'absolute -left-[48%] top-1/2 h-[620px] w-[1080px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.18)_0%,rgba(253,54,110,0.06)_32%,transparent_84%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.11)_0%,rgba(253,54,110,0.035)_32%,transparent_84%)]',
      'sm:-left-[44%] sm:h-[720px] sm:w-[1280px]',
      'lg:-left-[42%] lg:h-[820px] lg:w-[1480px]',
    ),
    right: cn(
      'absolute -right-[48%] top-1/2 h-[640px] w-[1100px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.055)_34%,transparent_86%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.095)_0%,rgba(124,103,254,0.035)_34%,transparent_86%)]',
      'sm:-right-[44%] sm:h-[740px] sm:w-[1300px]',
      'lg:-right-[42%] lg:h-[840px] lg:w-[1500px]',
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
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.1)_0%,rgba(124,103,254,0.035)_40%,transparent_74%)]',
      'sm:-right-[40%] sm:h-[600px] sm:w-[1040px]',
      'lg:-right-[38%] lg:h-[660px] lg:w-[1180px]',
    ),
  },
} as const

/** Tile-scoped lights for MCP / Skills / plugins bento — softer than hero, stronger than bare wash. */
const tileLights = {
  mcp: cn(
    'absolute -left-[36%] top-[-32%] h-[300px] w-[440px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.05)_0%,rgba(124,103,254,0.016)_42%,transparent_74%)]',
  ),
  skills: cn(
    'absolute -right-[36%] top-[-32%] h-[300px] w-[440px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.15)_0%,rgba(253,54,110,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.05)_0%,rgba(253,54,110,0.016)_42%,transparent_74%)]',
  ),
  plugins: cn(
    'absolute -left-[34%] bottom-[6%] h-[280px] w-[420px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.15)_0%,rgba(133,219,216,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.05)_0%,rgba(133,219,216,0.016)_42%,transparent_74%)]',
  ),
  integrations: cn(
    'absolute -right-[34%] bottom-[10%] h-[280px] w-[420px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.15)_0%,rgba(254,149,103,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.05)_0%,rgba(254,149,103,0.016)_42%,transparent_74%)]',
  ),
} as const

export type AiTileSoftLightTone = keyof typeof tileLights

export function AiTileSoftLight({ tone }: { tone: AiTileSoftLightTone }) {
  return (
    <div className="pointer-events-none absolute inset-0 overflow-visible" aria-hidden>
      <div className={tileLights[tone]} />
    </div>
  )
}

/** Neutral ambient wash for product bento visual frames — single top-left light. */
export function ProductBentoSoftLights() {
  return (
    <div
      className="pointer-events-none absolute inset-0 z-0 overflow-hidden rounded-[inherit] bg-[radial-gradient(ellipse_at_0%_0%,color-mix(in_srgb,var(--foreground)_2.5%,transparent)_0%,transparent_58%)] transition-opacity duration-300 group-hover:opacity-55 motion-reduce:group-hover:opacity-100"
      aria-hidden
    />
  )
}

/** Brand-tinted ambient light that fades in when a product bento tile is hovered. */
export function ProductBentoHoverLight() {
  return (
    <div
      className="product-bento-hover-light pointer-events-none absolute inset-0 overflow-hidden opacity-0 transition-opacity duration-300 group-hover:opacity-70 motion-reduce:transition-none motion-reduce:group-hover:opacity-0 dark:group-hover:opacity-55"
      aria-hidden
    >
      <div
        className={cn(
          'absolute -left-[38%] top-[-38%] h-[300px] w-[440px]',
          'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.09)_0%,rgba(253,54,110,0.03)_45%,transparent_78%)]',
          'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.06)_0%,rgba(253,54,110,0.018)_45%,transparent_78%)]',
        )}
      />
      <div
        className={cn(
          'absolute -right-[38%] bottom-[-38%] h-[280px] w-[420px]',
          'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.07)_0%,rgba(124,103,254,0.022)_45%,transparent_78%)]',
          'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.05)_0%,rgba(124,103,254,0.015)_45%,transparent_78%)]',
        )}
      />
    </div>
  )
}

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
        'pointer-events-none absolute inset-0 z-0 overflow-x-hidden overflow-y-visible',
        className,
      )}
      aria-hidden
    >
      <div className={lights.left} />
      <div className={lights.right} />
    </div>
  )
}

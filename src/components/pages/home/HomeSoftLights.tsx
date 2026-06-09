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
      'absolute -left-[52%] top-1/2 h-[780px] w-[1360px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.18)_0%,rgba(253,54,110,0.06)_24%,transparent_58%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.11)_0%,rgba(253,54,110,0.035)_24%,transparent_58%)]',
      'sm:-left-[48%] sm:h-[920px] sm:w-[1620px]',
      'lg:-left-[46%] lg:h-[1040px] lg:w-[1860px]',
    ),
    right: cn(
      'absolute -right-[52%] top-1/2 h-[800px] w-[1380px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.055)_26%,transparent_60%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.095)_0%,rgba(124,103,254,0.035)_26%,transparent_60%)]',
      'sm:-right-[48%] sm:h-[940px] sm:w-[1640px]',
      'lg:-right-[46%] lg:h-[1060px] lg:w-[1880px]',
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

/** Single ambient wash for marketing sections — one secondary brand tone. */
const singleSecondaryLightGradients = {
  purple: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.16)_0%,rgba(124,103,254,0.055)_36%,transparent_70%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.1)_0%,rgba(124,103,254,0.035)_36%,transparent_70%)]',
  ),
  /** Mint green secondary (#85DBD8) used on the home page plugins bento. */
  teal: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.16)_0%,rgba(133,219,216,0.055)_36%,transparent_70%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.1)_0%,rgba(133,219,216,0.035)_36%,transparent_70%)]',
  ),
  orange: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.16)_0%,rgba(254,149,103,0.055)_36%,transparent_70%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.1)_0%,rgba(254,149,103,0.035)_36%,transparent_70%)]',
  ),
} as const

const singleSecondaryLightHorizontal = {
  left: cn(
    'absolute -left-[36%]',
    'sm:-left-[32%]',
    'lg:-left-[28%]',
  ),
  right: cn(
    'absolute -right-[36%]',
    'sm:-right-[32%]',
    'lg:-right-[28%]',
  ),
} as const

const singleSecondaryLightSize = cn(
  'h-[620px] w-[980px]',
  'sm:h-[720px] sm:w-[1120px]',
  'lg:h-[800px] lg:w-[1240px]',
)

const singleSecondaryLightAlign = {
  center: 'top-1/2 -translate-y-1/2',
  top: 'top-[-26%]',
} as const

export type SecondarySoftLightTone = keyof typeof singleSecondaryLightGradients

export function SectionSoftLight({
  tone = 'purple',
  position = 'right',
  align = 'center',
  className,
}: {
  tone?: SecondarySoftLightTone
  position?: keyof typeof singleSecondaryLightHorizontal
  align?: keyof typeof singleSecondaryLightAlign
  className?: string
}) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-0 z-0 overflow-hidden',
        className,
      )}
      aria-hidden
    >
      <div
        className={cn(
          singleSecondaryLightHorizontal[position],
          singleSecondaryLightSize,
          singleSecondaryLightAlign[align],
          singleSecondaryLightGradients[tone],
        )}
      />
    </div>
  )
}

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
      className="pointer-events-none absolute inset-0 z-0 overflow-hidden rounded-lg bg-[radial-gradient(ellipse_at_0%_0%,color-mix(in_srgb,var(--foreground)_4%,transparent)_0%,transparent_62%)] transition-opacity duration-300 group-hover:opacity-70 motion-reduce:group-hover:opacity-100"
      aria-hidden
    />
  )
}

/** Brand-tinted ambient light that fades in when a product bento tile is hovered. */
export function ProductBentoHoverLight({ tall = false }: { tall?: boolean }) {
  return (
    <div
      className="product-bento-hover-light pointer-events-none absolute inset-0 overflow-hidden opacity-0 transition-opacity duration-300 group-hover:opacity-70 motion-reduce:transition-none motion-reduce:group-hover:opacity-0 dark:group-hover:opacity-55"
      aria-hidden
    >
      <div
        className={cn(
          'absolute bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.09)_0%,rgba(133,219,216,0.03)_45%,transparent_78%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.06)_0%,rgba(133,219,216,0.018)_45%,transparent_78%)]',
          tall
            ? '-left-[28%] top-[-28%] h-[440px] w-[680px]'
            : '-left-[38%] top-[-38%] h-[300px] w-[440px]',
        )}
      />
      <div
        className={cn(
          'absolute bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.07)_0%,rgba(124,103,254,0.022)_45%,transparent_78%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.05)_0%,rgba(124,103,254,0.015)_45%,transparent_78%)]',
          tall
            ? '-right-[28%] bottom-[-28%] h-[400px] w-[640px]'
            : '-right-[38%] bottom-[-38%] h-[280px] w-[420px]',
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

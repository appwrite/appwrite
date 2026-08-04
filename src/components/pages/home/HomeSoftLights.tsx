import { cn } from '@/lib/utils'

/**
 * Soft ambient gradients without CSS blur filters - large blurs repaint the full
 * viewport on scroll and cause severe jank on the marketing home page.
 *
 * Blue/purple page wash uses #7C67FE (rgb 124, 103, 254) in light and dark mode.
 */
const variants = {
  hero: {
    left: cn(
      'absolute -start-[42%] bottom-[-32%] h-[480px] w-[820px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.2)_0%,rgba(253,54,110,0.07)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.12)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
      'sm:-start-[38%] sm:h-[560px] sm:w-[980px]',
      'lg:-start-[36%] lg:h-[640px] lg:w-[1120px]',
    ),
    right: cn(
      'absolute -end-[44%] bottom-[-34%] h-[500px] w-[840px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.17)_0%,rgba(124,103,254,0.06)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.04)_40%,transparent_74%)]',
      'sm:-end-[40%] sm:h-[580px] sm:w-[1000px]',
      'lg:-end-[38%] lg:h-[660px] lg:w-[1140px]',
    ),
  },
  pricing: {
    left: cn(
      'absolute -start-[52%] top-1/2 h-[780px] w-[1360px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.18)_0%,rgba(253,54,110,0.06)_24%,transparent_58%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.11)_0%,rgba(253,54,110,0.035)_24%,transparent_58%)]',
      'sm:-start-[48%] sm:h-[920px] sm:w-[1620px]',
      'lg:-start-[46%] lg:h-[1040px] lg:w-[1860px]',
    ),
    right: cn(
      'absolute -end-[52%] top-1/2 h-[800px] w-[1380px] -translate-y-1/2',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.055)_26%,transparent_60%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.095)_0%,rgba(124,103,254,0.035)_26%,transparent_60%)]',
      'sm:-end-[48%] sm:h-[940px] sm:w-[1640px]',
      'lg:-end-[46%] lg:h-[1060px] lg:w-[1880px]',
    ),
  },
  /** Docs hero: pink wash below, brand mint green (#85DBD8) at the top instead of purple. */
  docs: {
    left: cn(
      'absolute -start-[42%] bottom-[-32%] h-[480px] w-[820px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.2)_0%,rgba(253,54,110,0.07)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.12)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
      'sm:-start-[38%] sm:h-[560px] sm:w-[980px]',
      'lg:-start-[36%] lg:h-[640px] lg:w-[1120px]',
    ),
    right: cn(
      'absolute -end-[40%] top-[-28%] h-[500px] w-[840px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.17)_0%,rgba(133,219,216,0.06)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.11)_0%,rgba(133,219,216,0.04)_40%,transparent_74%)]',
      'sm:-end-[36%] sm:top-[-30%] sm:h-[580px] sm:w-[1000px]',
      'lg:-end-[34%] lg:top-[-32%] lg:h-[660px] lg:w-[1140px]',
    ),
  },
  /** Partners docs hero: warm orange bottom-left, purple wash top-right. */
  partners: {
    left: cn(
      'absolute -start-[42%] bottom-[-32%] h-[480px] w-[820px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.17)_0%,rgba(254,149,103,0.06)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.11)_0%,rgba(254,149,103,0.04)_38%,transparent_72%)]',
      'sm:-start-[38%] sm:h-[560px] sm:w-[980px]',
      'lg:-start-[36%] lg:h-[640px] lg:w-[1120px]',
    ),
    right: cn(
      'absolute -end-[40%] top-[-30%] h-[500px] w-[860px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.18)_0%,rgba(124,103,254,0.06)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.04)_40%,transparent_74%)]',
      'sm:-end-[36%] sm:top-[-32%] sm:h-[580px] sm:w-[1020px]',
      'lg:-end-[34%] lg:top-[-34%] lg:h-[660px] lg:w-[1160px]',
    ),
  },
  testimonials: {
    left: cn(
      'absolute -start-[44%] bottom-[-34%] h-[500px] w-[860px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.18)_0%,rgba(253,54,110,0.06)_38%,transparent_72%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.11)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
      'sm:-start-[40%] sm:h-[580px] sm:w-[1020px]',
      'lg:-start-[38%] lg:h-[640px] lg:w-[1160px]',
    ),
    right: cn(
      'absolute -end-[44%] bottom-[-34%] h-[520px] w-[880px]',
      'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.16)_0%,rgba(124,103,254,0.055)_40%,transparent_74%)]',
      'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.1)_0%,rgba(124,103,254,0.035)_40%,transparent_74%)]',
      'sm:-end-[40%] sm:h-[600px] sm:w-[1040px]',
      'lg:-end-[38%] lg:h-[660px] lg:w-[1180px]',
    ),
  },
} as const

/** Single ambient wash for marketing sections - one secondary brand tone. */
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
    'absolute -start-[36%]',
    'sm:-start-[32%]',
    'lg:-start-[28%]',
  ),
  right: cn(
    'absolute -end-[36%]',
    'sm:-end-[32%]',
    'lg:-end-[28%]',
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

/** Brand wash for product feature sections (e.g. providers grid). */
const brandLightGradients = {
  pink: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.2)_0%,rgba(253,54,110,0.07)_38%,transparent_72%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.12)_0%,rgba(253,54,110,0.04)_38%,transparent_72%)]',
  ),
  purple: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.17)_0%,rgba(124,103,254,0.06)_38%,transparent_72%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.04)_38%,transparent_72%)]',
  ),
  teal: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.17)_0%,rgba(133,219,216,0.06)_38%,transparent_72%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.11)_0%,rgba(133,219,216,0.04)_38%,transparent_72%)]',
  ),
  orange: cn(
    'bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.17)_0%,rgba(254,149,103,0.06)_38%,transparent_72%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.11)_0%,rgba(254,149,103,0.04)_38%,transparent_72%)]',
  ),
} as const

export type BrandLightTone = keyof typeof brandLightGradients

const brandLightPositionClasses = {
  top: cn(
    'absolute top-[-32%] left-1/2 h-[560px] w-[min(1200px,140%)] -translate-x-1/2',
    'sm:top-[-34%] sm:h-[640px]',
  ),
  bottom: cn(
    'absolute bottom-[-32%] left-1/2 h-[560px] w-[min(1200px,140%)] -translate-x-1/2',
    'sm:bottom-[-34%] sm:h-[640px]',
  ),
} as const

export function SectionBrandLight({
  tone = 'pink',
  position = 'top',
}: {
  tone?: BrandLightTone
  position?: keyof typeof brandLightPositionClasses
}) {
  return (
    <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden" aria-hidden>
      <div className={cn(brandLightPositionClasses[position], brandLightGradients[tone])} />
    </div>
  )
}

export function SectionDottedBackground({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]',
        className,
      )}
      aria-hidden
    />
  )
}

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

/** Tile-scoped lights for MCP / Skills / plugins bento - softer than hero, stronger than bare wash. */
const tileLights = {
  mcp: cn(
    'absolute -start-[36%] top-[-32%] h-[300px] w-[440px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.05)_0%,rgba(124,103,254,0.016)_42%,transparent_74%)]',
  ),
  skills: cn(
    'absolute -end-[36%] top-[-32%] h-[300px] w-[440px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.15)_0%,rgba(253,54,110,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.05)_0%,rgba(253,54,110,0.016)_42%,transparent_74%)]',
  ),
  plugins: cn(
    'absolute -start-[34%] bottom-[6%] h-[280px] w-[420px]',
    'bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.15)_0%,rgba(133,219,216,0.055)_42%,transparent_74%)]',
    'dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.05)_0%,rgba(133,219,216,0.016)_42%,transparent_74%)]',
  ),
  integrations: cn(
    'absolute -end-[34%] bottom-[10%] h-[280px] w-[420px]',
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

/** Neutral ambient wash for product bento visual frames - single top-left light. */
export function ProductBentoSoftLights({
  blend = false,
  expanded = false,
}: {
  blend?: boolean
  expanded?: boolean
}) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-0 z-0 transition-opacity duration-300 group-hover:opacity-70 motion-reduce:transition-none motion-reduce:group-hover:opacity-100',
        blend
          ? cn(
              'overflow-visible',
              expanded
                ? 'bg-[radial-gradient(ellipse_120%_100%_at_50%_50%,color-mix(in_srgb,var(--foreground)_6%,transparent)_0%,transparent_75%)]'
                : 'bg-[radial-gradient(ellipse_at_50%_42%,color-mix(in_srgb,var(--foreground)_6%,transparent)_0%,transparent_70%)]',
            )
          : 'overflow-hidden rounded-lg bg-[radial-gradient(ellipse_at_0%_0%,color-mix(in_srgb,var(--foreground)_4%,transparent)_0%,transparent_62%)]',
      )}
      aria-hidden
    />
  )
}

/** Always-on brand glow behind product page hero visuals (16:9 frame). */
export function ProductPageBrandLight() {
  return (
    <div className="pointer-events-none absolute inset-0 overflow-visible" aria-hidden>
      <div className="absolute -start-[22%] top-[2%] h-[96%] w-[144%] bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.14)_0%,rgba(133,219,216,0.045)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.09)_0%,rgba(133,219,216,0.028)_42%,transparent_76%)]" />
      <div className="absolute -end-[22%] bottom-[2%] h-[96%] w-[144%] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.11)_0%,rgba(124,103,254,0.034)_42%,transparent_76%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.075)_0%,rgba(124,103,254,0.022)_42%,transparent_76%)]" />
      <div className="absolute left-1/2 top-1/2 h-[88%] w-[72%] -translate-x-1/2 -translate-y-1/2 bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_10%,transparent)_0%,transparent_68%)] dark:bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_7%,transparent)_0%,transparent_68%)]" />
    </div>
  )
}

/** Brand-tinted ambient light that fades in when a product bento tile is hovered. */
export function ProductBentoHoverLight({
  tall = false,
  unclipped = false,
  productPage = false,
}: {
  tall?: boolean
  unclipped?: boolean
  productPage?: boolean
}) {
  return (
    <div
      className={cn(
        'product-bento-hover-light pointer-events-none absolute inset-0 invisible opacity-0 transition-opacity duration-300 group-hover:visible group-hover:opacity-70 motion-reduce:transition-none motion-reduce:invisible motion-reduce:group-hover:invisible motion-reduce:group-hover:opacity-0 dark:group-hover:opacity-55',
        unclipped ? 'overflow-visible' : 'overflow-hidden',
      )}
      aria-hidden
    >
      <div
        className={cn(
          'absolute bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.09)_0%,rgba(133,219,216,0.03)_45%,transparent_78%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.06)_0%,rgba(133,219,216,0.018)_45%,transparent_78%)]',
          productPage
            ? '-start-[18%] top-[0%] h-full w-[136%]'
            : tall
              ? '-start-[28%] top-[-28%] h-[440px] w-[680px]'
              : '-start-[38%] top-[-38%] h-[300px] w-[440px]',
        )}
      />
      <div
        className={cn(
          'absolute bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.07)_0%,rgba(124,103,254,0.022)_45%,transparent_78%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.05)_0%,rgba(124,103,254,0.015)_45%,transparent_78%)]',
          productPage
            ? '-end-[18%] bottom-[0%] h-full w-[136%]'
            : tall
              ? '-end-[28%] bottom-[-28%] h-[400px] w-[640px]'
              : '-end-[38%] bottom-[-38%] h-[280px] w-[420px]',
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
        /* overflow-hidden: overflow-x-hidden + overflow-y-visible computes to overflow-y:auto
           (CSS overflow equivalence), which creates a nested scrollbar on Linux inside main. */
        'pointer-events-none absolute inset-0 z-0 overflow-hidden',
        className,
      )}
      aria-hidden
    >
      <div className={lights.left} />
      <div className={lights.right} />
    </div>
  )
}

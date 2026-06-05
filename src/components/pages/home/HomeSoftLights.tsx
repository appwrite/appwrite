import { cn } from '@/lib/utils'

const HOME_SOFT_LIGHT_KEYFRAMES = `
  @keyframes home-hero-soft-light-left-light {
    0% { transform: translate3d(0, 56%, 0) rotate(-3deg) scale(1); opacity: 0.78; }
    100% { transform: translate3d(14%, -8%, 0) rotate(4deg) scale(1.14); opacity: 1; }
  }

  @keyframes home-hero-soft-light-right-light {
    0% { transform: translate3d(0, 58%, 0) rotate(3deg) scale(1); opacity: 0.75; }
    100% { transform: translate3d(-14%, -10%, 0) rotate(-4deg) scale(1.12); opacity: 1; }
  }

  @keyframes home-hero-soft-light-left-dark {
    0% { transform: translate3d(0, 56%, 0) rotate(-3deg) scale(1); opacity: 0.62; }
    100% { transform: translate3d(14%, -8%, 0) rotate(4deg) scale(1.14); opacity: 0.95; }
  }

  @keyframes home-hero-soft-light-right-dark {
    0% { transform: translate3d(0, 58%, 0) rotate(3deg) scale(1); opacity: 0.58; }
    100% { transform: translate3d(-14%, -10%, 0) rotate(-4deg) scale(1.12); opacity: 0.92; }
  }
`

const lightBaseClass =
  'rounded-[999px] blur-[88px] [animation-duration:3.5s] [animation-timing-function:ease-out] [animation-iteration-count:1] [animation-fill-mode:forwards]'

const bottomAnchoredClass =
  'bottom-[-48%] sm:bottom-[-44%] lg:bottom-[-40%]'

const variants = {
  hero: {
    left: cn(
      lightBaseClass,
      'absolute -left-[58%] h-[360px] w-[720px]',
      bottomAnchoredClass,
      'bg-[radial-gradient(ellipse,rgba(253,54,110,0.34)_0%,rgba(253,54,110,0.12)_44%,transparent_76%)]',
      '[animation-name:home-hero-soft-light-left-light]',
      'dark:bg-[radial-gradient(ellipse,rgba(253,54,110,0.15)_0%,rgba(253,54,110,0.055)_44%,transparent_76%)]',
      'dark:[animation-name:home-hero-soft-light-left-dark]',
      'sm:-left-[52%] sm:h-[520px] sm:w-[1080px]',
      'lg:-left-[50%] lg:h-[680px] lg:w-[1380px]',
    ),
    right: cn(
      lightBaseClass,
      'absolute -right-[60%] h-[380px] w-[740px]',
      bottomAnchoredClass,
      '[animation-duration:4s] [animation-name:home-hero-soft-light-right-light]',
      'bg-[radial-gradient(ellipse,rgba(124,103,254,0.3)_0%,rgba(124,103,254,0.1)_46%,transparent_78%)]',
      'dark:bg-[radial-gradient(ellipse,rgba(99,102,241,0.14)_0%,rgba(99,102,241,0.05)_46%,transparent_78%)]',
      'dark:[animation-name:home-hero-soft-light-right-dark]',
      'sm:-right-[54%] sm:h-[560px] sm:w-[1140px]',
      'lg:-right-[52%] lg:h-[720px] lg:w-[1480px]',
    ),
  },
  testimonials: {
    left: cn(
      lightBaseClass,
      'absolute -left-[64%] h-[400px] w-[760px]',
      bottomAnchoredClass,
      'bg-[radial-gradient(ellipse,rgba(253,54,110,0.32)_0%,rgba(253,54,110,0.11)_44%,transparent_76%)]',
      '[animation-name:home-hero-soft-light-left-light]',
      'dark:bg-[radial-gradient(ellipse,rgba(253,54,110,0.14)_0%,rgba(253,54,110,0.05)_44%,transparent_76%)]',
      'dark:[animation-name:home-hero-soft-light-left-dark]',
      'sm:-left-[58%] sm:h-[540px] sm:w-[1120px]',
      'lg:-left-[54%] lg:h-[680px] lg:w-[1320px]',
    ),
    right: cn(
      lightBaseClass,
      'absolute -right-[64%] h-[420px] w-[780px]',
      bottomAnchoredClass,
      '[animation-duration:4s] [animation-name:home-hero-soft-light-right-light]',
      'bg-[radial-gradient(ellipse,rgba(124,103,254,0.28)_0%,rgba(124,103,254,0.1)_46%,transparent_78%)]',
      'dark:bg-[radial-gradient(ellipse,rgba(99,102,241,0.13)_0%,rgba(99,102,241,0.045)_46%,transparent_78%)]',
      'dark:[animation-name:home-hero-soft-light-right-dark]',
      'sm:-right-[58%] sm:h-[580px] sm:w-[1180px]',
      'lg:-right-[54%] lg:h-[720px] lg:w-[1420px]',
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
    <>
      <div
        className={cn(
          'pointer-events-none absolute inset-y-0 left-1/2 z-0 w-screen -translate-x-1/2 overflow-hidden motion-reduce:hidden',
          className,
        )}
        aria-hidden
      >
        <div className={lights.left} />
        <div className={lights.right} />
      </div>
      <style>{HOME_SOFT_LIGHT_KEYFRAMES}</style>
    </>
  )
}

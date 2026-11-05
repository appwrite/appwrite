import { useCallback, useEffect, useState } from 'react'
import {
  Carousel,
  CarouselContent,
  CarouselItem,
  CarouselNext,
  CarouselPrevious,
  type CarouselApi,
} from '@/components/ui/carousel'
import { DOCS_HOME_TUTORIALS } from '@/lib/docs/home-content'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '../DocsRouteLink'

const TUTORIAL_COVER_LIGHT_PAIRS = [
  [
    'absolute -start-[32%] -top-[45%] h-[150px] w-[170px] bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.3)_0%,rgba(133,219,216,0.11)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.17)_0%,rgba(133,219,216,0.055)_40%,transparent_72%)]',
    'absolute -end-[28%] -bottom-[40%] h-[130px] w-[150px] bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.24)_0%,rgba(253,54,110,0.085)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.14)_0%,rgba(253,54,110,0.045)_40%,transparent_72%)]',
  ],
  [
    'absolute -end-[30%] -top-[42%] h-[145px] w-[165px] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.26)_0%,rgba(124,103,254,0.09)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.15)_0%,rgba(124,103,254,0.048)_40%,transparent_72%)]',
    'absolute -start-[26%] -bottom-[38%] h-[125px] w-[145px] bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.22)_0%,rgba(254,149,103,0.075)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.13)_0%,rgba(254,149,103,0.04)_40%,transparent_72%)]',
  ],
  [
    'absolute start-[10%] -top-[50%] h-[155px] w-[175px] bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.26)_0%,rgba(253,54,110,0.09)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.15)_0%,rgba(253,54,110,0.048)_40%,transparent_72%)]',
    'absolute -end-[22%] bottom-[-30%] h-[120px] w-[140px] bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.22)_0%,rgba(133,219,216,0.075)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.13)_0%,rgba(133,219,216,0.04)_40%,transparent_72%)]',
  ],
  [
    'absolute -start-[24%] top-[5%] h-[140px] w-[160px] bg-[radial-gradient(ellipse_at_center,color-mix(in_srgb,var(--brand-cta)_24%,transparent)_0%,color-mix(in_srgb,var(--brand-cta)_8%,transparent)_40%,transparent_72%)]',
    'absolute -end-[30%] -top-[35%] h-[135px] w-[155px] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.22)_0%,rgba(124,103,254,0.075)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.13)_0%,rgba(124,103,254,0.04)_40%,transparent_72%)]',
  ],
  [
    'absolute -end-[26%] top-[0%] h-[150px] w-[170px] bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.24)_0%,rgba(254,149,103,0.085)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(254,149,103,0.14)_0%,rgba(254,149,103,0.045)_40%,transparent_72%)]',
    'absolute -start-[28%] -bottom-[42%] h-[130px] w-[150px] bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.24)_0%,rgba(133,219,216,0.085)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(133,219,216,0.14)_0%,rgba(133,219,216,0.045)_40%,transparent_72%)]',
  ],
  [
    'absolute -start-[34%] -top-[38%] h-[145px] w-[165px] bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.24)_0%,rgba(124,103,254,0.085)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(124,103,254,0.14)_0%,rgba(124,103,254,0.045)_40%,transparent_72%)]',
    'absolute end-[5%] -bottom-[35%] h-[125px] w-[145px] bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.22)_0%,rgba(253,54,110,0.075)_40%,transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,rgba(253,54,110,0.13)_0%,rgba(253,54,110,0.04)_40%,transparent_72%)]',
  ],
] as const

function TutorialCoverLights({ variant }: { variant: number }) {
  const [primary, secondary] =
    TUTORIAL_COVER_LIGHT_PAIRS[variant % TUTORIAL_COVER_LIGHT_PAIRS.length]

  return (
    <div
      className="pointer-events-none absolute inset-0 overflow-hidden opacity-95 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:transition-none"
      aria-hidden
    >
      <div className={primary} />
      <div className={secondary} />
    </div>
  )
}

export function DocsTutorialsScroll() {
  const [api, setApi] = useState<CarouselApi>()
  const [showRightFade, setShowRightFade] = useState(false)

  const updateFade = useCallback((carouselApi: CarouselApi | undefined) => {
    if (!carouselApi) return
    setShowRightFade(carouselApi.canScrollNext())
  }, [])

  useEffect(() => {
    if (!api) return

    updateFade(api)

    const onSelect = () => updateFade(api)
    api.on('reInit', onSelect)
    api.on('select', onSelect)

    return () => {
      api.off('reInit', onSelect)
      api.off('select', onSelect)
    }
  }, [api, updateFade])

  return (
    <Carousel
      opts={{ align: 'start', dragFree: true }}
      setApi={setApi}
      className="w-full"
    >
      <div className="relative min-w-0 overflow-hidden">
        <CarouselContent className="-ms-4">
          {DOCS_HOME_TUTORIALS.map((tutorial, index) => (
            <CarouselItem
              key={tutorial.href}
              className="basis-[280px] ps-4 @[480px]:basis-[300px]"
            >
              <DocsRouteLink
                href={tutorial.href}
                className="group flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card/45 transition-colors hover:bg-accent/15"
              >
                <div className="relative flex h-32 items-center justify-center overflow-hidden border-b border-border bg-background/50">
                  <TutorialCoverLights variant={index} />
                  <div
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:14px_14px] opacity-35"
                    aria-hidden
                  />
                  <img
                    src={tutorial.iconSrc}
                    alt=""
                    className={cn('relative z-10 size-12', PUBLIC_ICON_MUTED_CLASSES)}
                  />
                </div>
                <div className="p-5">
                  <h3 className="text-[13px] font-medium text-foreground">
                    {tutorial.title}
                  </h3>
                  <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                    {tutorial.description}
                  </p>
                </div>
              </DocsRouteLink>
            </CarouselItem>
          ))}
        </CarouselContent>

        <div
          aria-hidden
          className={cn(
            'pointer-events-none absolute end-0 top-0 bottom-0 z-10 w-20 bg-gradient-to-l from-background via-background/80 to-transparent transition-opacity duration-200 @[480px]:w-24',
            showRightFade ? 'opacity-100' : 'opacity-0',
          )}
        />
      </div>

      <div className="mt-4 flex justify-end gap-2">
        <CarouselPrevious
          variant="outline"
          size="icon"
          className="static size-8 translate-x-0 translate-y-0 rounded-full"
        />
        <CarouselNext
          variant="outline"
          size="icon"
          className="static size-8 translate-x-0 translate-y-0 rounded-full"
        />
      </div>
    </Carousel>
  )
}

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
        <CarouselContent className="-ml-4">
          {DOCS_HOME_TUTORIALS.map((tutorial) => (
            <CarouselItem
              key={tutorial.href}
              className="basis-[280px] pl-4 sm:basis-[300px]"
            >
              <DocsRouteLink
                href={tutorial.href}
                className="group flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card/45 transition-colors hover:bg-accent/15"
              >
                <div className="flex h-32 items-center justify-center border-b border-border bg-muted/20">
                  <img
                    src={tutorial.iconSrc}
                    alt=""
                    className={cn('size-12', PUBLIC_ICON_MUTED_CLASSES)}
                  />
                </div>
                <div className="p-5">
                  <h3 className="text-[14px] font-semibold text-foreground">
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
            'pointer-events-none absolute right-0 top-0 bottom-0 z-10 w-20 bg-gradient-to-l from-background via-background/80 to-transparent transition-opacity duration-200 sm:w-24',
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

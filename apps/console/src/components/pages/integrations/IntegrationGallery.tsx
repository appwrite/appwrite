import { useCallback, useEffect, useState } from 'react'
import {
  Carousel,
  CarouselContent,
  CarouselItem,
  CarouselNext,
  CarouselPrevious,
  type CarouselApi,
} from '@/components/ui/carousel'
import { cn } from '@/lib/utils'

type IntegrationGalleryProps = {
  images: string[]
  className?: string
}

export function IntegrationGallery({ images, className }: IntegrationGalleryProps) {
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

  if (images.length === 0) return null

  return (
    <div className={cn('w-full', className)}>
      <Carousel
        opts={{ align: 'start', dragFree: true }}
        setApi={setApi}
        className="w-full"
      >
        <div className="relative min-w-0 overflow-hidden">
          <CarouselContent className="-ms-4">
            {images.map((image, index) => (
              <CarouselItem
                key={`${image}-${index}`}
                className="basis-[280px] ps-4 sm:basis-[360px] lg:basis-[420px]"
              >
                <div className="overflow-hidden rounded-xl border border-border bg-background shadow-sm">
                  <img
                    src={image}
                    alt=""
                    className="aspect-[16/9] w-full object-cover"
                    loading={index === 0 ? 'eager' : 'lazy'}
                    draggable={false}
                  />
                </div>
              </CarouselItem>
            ))}
          </CarouselContent>

          <div
            aria-hidden
            className={cn(
              'pointer-events-none absolute end-0 top-0 bottom-0 z-10 w-20 bg-gradient-to-l from-muted/20 to-transparent transition-opacity duration-200 sm:w-24',
              showRightFade ? 'opacity-100' : 'opacity-0',
            )}
          />
        </div>

        {images.length > 1 ? (
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
        ) : null}
      </Carousel>
    </div>
  )
}

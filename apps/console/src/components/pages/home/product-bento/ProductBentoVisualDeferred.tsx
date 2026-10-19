'use client'

import { useRef } from 'react'
import { useIntersectionVisible } from '@/hooks/use-intersection-visible'
import { ProductBentoVisual } from './ProductBentoVisual'

type ProductBentoVisualId =
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'sites'
  | 'messaging'
  | 'firewall'
  | 'realtime'

/** Mounts the interactive mock only when the tile is near the viewport. */
export function ProductBentoVisualDeferred({
  productId,
}: {
  productId: ProductBentoVisualId
}) {
  const ref = useRef<HTMLDivElement>(null)
  const { hasBeenVisible } = useIntersectionVisible(ref, {
    rootMargin: '240px 0px',
    once: true,
  })

  return (
    <div ref={ref} className="relative h-full min-h-0 w-full">
      {hasBeenVisible ? <ProductBentoVisual productId={productId} /> : null}
    </div>
  )
}

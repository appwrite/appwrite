'use client'

import { ImageIcon, Maximize2 } from 'lucide-react'
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { ThinkingBubble } from '@/components/global/shared/ThinkingBubble'
import {
  ImagePreviewGalleryDialog,
  useImagePreviewGallery,
} from '@/components/global/shared/ImagePreviewGallery'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const DocsMarkdocInTableContext = createContext(false)

export function DocsMarkdocInTableProvider({
  children,
}: {
  children: ReactNode
}) {
  return (
    <DocsMarkdocInTableContext.Provider value={true}>
      {children}
    </DocsMarkdocInTableContext.Provider>
  )
}

const AUDIO_SRC_RE = /\.(wav|mp3|m4a|ogg)$/i

type DocsImageProps = {
  src?: string
  alt?: string
  title?: string
}

export function DocsImage({ src, alt = '', title }: DocsImageProps) {
  const inTable = useContext(DocsMarkdocInTableContext)
  const gallery = useImagePreviewGallery()
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState(false)
  const [localPreviewOpen, setLocalPreviewOpen] = useState(false)

  useEffect(() => {
    if (!gallery || !src || inTable || AUDIO_SRC_RE.test(src)) return
    return gallery.registerItem({ src, alt })
  }, [gallery, src, alt, inTable])

  if (!src) return null

  const contain = title === 'contain'
  const isAudio = AUDIO_SRC_RE.test(src)

  if (inTable || isAudio) {
    if (isAudio) {
      return (
        <audio src={src} controls className="w-full">
          Your browser does not support the audio element.
        </audio>
      )
    }

    return (
      <img
        src={src}
        alt={alt}
        title={contain ? undefined : title}
        loading="lazy"
        className="max-w-full align-middle"
      />
    )
  }

  const imageClassName = cn(
    'h-full w-full rounded-lg object-contain transition-opacity duration-300',
    loaded ? 'opacity-100' : 'opacity-0',
    contain && 'p-3 @[480px]:p-4',
  )

  const openPreview = () => {
    if (gallery) {
      gallery.openItem({ src, alt })
      return
    }
    setLocalPreviewOpen(true)
  }

  return (
    <figure className="not-prose group relative my-8 w-full">
      <div className="overflow-hidden rounded-xl border border-border bg-card/40 p-3 shadow-sm">
        <div
          className={cn(
            'relative aspect-video w-full overflow-hidden rounded-lg bg-muted/25',
          )}
        >
          {!loaded && !error ? (
            <div className="absolute inset-0 flex items-center justify-center bg-muted/30">
              <ThinkingBubble
                size={40}
                activity={0.18}
                interactive={false}
                particleCount={120}
                colorMode="brand"
                centered
              />
            </div>
          ) : null}

          {error ? (
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-muted/30 text-muted-foreground">
              <ImageIcon className="size-6" aria-hidden />
              <span className="text-[12px]">Image unavailable</span>
            </div>
          ) : (
            <img
              src={src}
              alt={alt}
              title={contain ? undefined : title}
              loading="lazy"
              className={imageClassName}
              onLoad={() => setLoaded(true)}
              onError={() => {
                setError(true)
                setLoaded(true)
              }}
            />
          )}

          {!error ? (
            <Button
              type="button"
              variant="secondary"
              size="icon"
              className="absolute end-3 bottom-3 size-8 border border-border/80 bg-background/90 opacity-70 shadow-sm backdrop-blur-sm transition-opacity hover:opacity-100 focus-visible:opacity-100"
              aria-label="Expand image"
              onClick={openPreview}
            >
              <Maximize2 className="size-3.5" />
            </Button>
          ) : null}
        </div>
      </div>

      {alt.trim() ? (
        <figcaption className="mt-2.5 text-center text-[12px] leading-5 text-muted-foreground">
          {alt}
        </figcaption>
      ) : null}

      {!gallery && !error ? (
        <ImagePreviewGalleryDialog
          items={[{ src, alt }]}
          activeIndex={localPreviewOpen ? 0 : null}
          onActiveIndexChange={(index) => setLocalPreviewOpen(index !== null)}
        />
      ) : null}
    </figure>
  )
}

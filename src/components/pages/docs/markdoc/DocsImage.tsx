'use client'

import { ImageIcon, Maximize2 } from 'lucide-react'
import { createContext, useContext, useState, type ReactNode } from 'react'
import { ThinkingBubble } from '@/components/global/shared/ThinkingBubble'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
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
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState(false)
  const [open, setOpen] = useState(false)

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
    'h-full w-full transition-opacity duration-300',
    loaded ? 'opacity-100' : 'opacity-0',
    contain ? 'object-contain p-3 sm:p-4' : 'object-cover',
  )

  return (
    <figure className="not-prose group relative my-8 w-full">
      <div className="overflow-hidden rounded-xl border border-border bg-card/40 shadow-sm">
        <div
          className={cn(
            'relative w-full bg-muted/25',
            contain ? 'min-h-[12rem]' : 'aspect-video',
          )}
        >
          <div
            className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_50%_0%,color-mix(in_srgb,var(--foreground)_5%,transparent),transparent_68%)]"
            aria-hidden
          />

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
            <Dialog open={open} onOpenChange={setOpen}>
              <DialogTrigger asChild>
                <Button
                  type="button"
                  variant="secondary"
                  size="icon"
                  className="absolute right-3 bottom-3 size-8 border border-border/80 bg-background/90 opacity-70 shadow-sm backdrop-blur-sm transition-opacity hover:opacity-100 focus-visible:opacity-100"
                  aria-label="Expand image"
                >
                  <Maximize2 className="size-3.5" />
                </Button>
              </DialogTrigger>
              <DialogContent
                showCloseButton
                overlayClassName="bg-background/95"
                className="max-h-[92dvh] w-[min(96vw,1200px)] max-w-[min(96vw,1200px)] gap-0 overflow-hidden border-0 bg-transparent p-0 shadow-none sm:max-w-[min(96vw,1200px)]"
              >
                <DialogTitle className="sr-only">
                  {alt.trim() ? alt : 'Expanded image'}
                </DialogTitle>
                <img
                  src={src}
                  alt={alt}
                  className="max-h-[85dvh] w-full rounded-lg object-contain"
                />
              </DialogContent>
            </Dialog>
          ) : null}
        </div>
      </div>

      {alt.trim() ? (
        <figcaption className="mt-2.5 text-center text-[12px] leading-5 text-muted-foreground">
          {alt}
        </figcaption>
      ) : null}
    </figure>
  )
}

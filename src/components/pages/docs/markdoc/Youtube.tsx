'use client'

import { Play } from 'lucide-react'
import { useCallback, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

type MarkdocYoutubeProps = {
  src?: string
  thumbnail?: string
  id?: string
  title?: string
}

function resolveEmbedSrc(src?: string, id?: string): string | null {
  if (src?.trim()) return src.trim()
  if (id?.trim()) {
    return `https://www.youtube-nocookie.com/embed/${id.trim()}`
  }
  return null
}

function resolveThumbnail(thumbnail?: string, id?: string): string | null {
  if (thumbnail?.trim()) return thumbnail.trim()
  if (id?.trim()) {
    return `https://i.ytimg.com/vi/${id.trim()}/hqdefault.jpg`
  }
  return null
}

function withAutoplay(embedSrc: string): string {
  try {
    const url = new URL(embedSrc)
    url.searchParams.set('autoplay', '1')
    return url.toString()
  } catch {
    const separator = embedSrc.includes('?') ? '&' : '?'
    return `${embedSrc}${separator}autoplay=1`
  }
}

export function MarkdocYoutube({ src, thumbnail, id, title }: MarkdocYoutubeProps) {
  const [open, setOpen] = useState(false)
  const [playerKey, setPlayerKey] = useState(0)

  const embedSrc = resolveEmbedSrc(src, id)
  const thumbnailSrc = resolveThumbnail(thumbnail, id)

  const handleOpenChange = useCallback((nextOpen: boolean) => {
    setOpen(nextOpen)
    if (!nextOpen) {
      window.setTimeout(() => {
        setPlayerKey((current) => current + 1)
      }, 200)
    }
  }, [])

  if (!embedSrc) return null

  const dialogTitle = title?.trim() || 'YouTube video'

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <div className="not-prose my-8">
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="group relative block w-full cursor-pointer overflow-hidden rounded-xl border border-border bg-muted/25 text-start"
          aria-label={`Play ${dialogTitle}`}
        >
          <div className="relative aspect-video w-full">
            {thumbnailSrc ? (
              <img
                src={thumbnailSrc}
                alt=""
                loading="lazy"
                decoding="async"
                className="size-full object-cover grayscale transition-[filter] duration-300 group-hover:grayscale-0"
              />
            ) : (
              <div className="size-full bg-muted/40" />
            )}

            <div
              className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_50%_0%,color-mix(in_srgb,var(--foreground)_5%,transparent),transparent_68%)]"
              aria-hidden
            />

            <span className="absolute start-1/2 top-1/2 flex size-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-white/10 text-foreground shadow-sm backdrop-blur-md transition-transform duration-150 group-hover:scale-105 group-active:scale-95">
              <Play className="ms-0.5 size-4 fill-current" aria-hidden />
            </span>
          </div>
        </button>
      </div>

      <DialogContent
        showCloseButton
        overlayClassName="z-[120] bg-black/80 backdrop-blur-md"
        className={cn(
          'z-[120] w-[min(92vw,960px)] max-w-none gap-0 overflow-visible rounded-2xl border border-border/80',
          'bg-card/95 p-3 shadow-2xl ring-1 ring-foreground/[0.06] backdrop-blur-xl sm:p-4 sm:max-w-[960px]',
          '[&_[data-slot=dialog-close]]:-top-3 [&_[data-slot=dialog-close]]:-end-3 sm:[&_[data-slot=dialog-close]]:-top-3.5 sm:[&_[data-slot=dialog-close]]:-end-3.5',
          '[&_[data-slot=dialog-close]]:z-20 [&_[data-slot=dialog-close]]:flex [&_[data-slot=dialog-close]]:size-9 [&_[data-slot=dialog-close]]:shrink-0',
          '[&_[data-slot=dialog-close]]:items-center [&_[data-slot=dialog-close]]:justify-center',
          '[&_[data-slot=dialog-close]]:rounded-full [&_[data-slot=dialog-close]]:border [&_[data-slot=dialog-close]]:border-border/80',
          '[&_[data-slot=dialog-close]]:bg-background [&_[data-slot=dialog-close]]:opacity-100',
          '[&_[data-slot=dialog-close]]:shadow-md [&_[data-slot=dialog-close]]:backdrop-blur-sm',
          '[&_[data-slot=dialog-close]:hover]:bg-background',
          '[&_[data-slot=dialog-close]_svg]:size-4',
        )}
      >
        <DialogTitle className="sr-only">{dialogTitle}</DialogTitle>

        <div className="relative overflow-hidden rounded-xl border border-border/70 bg-black shadow-[inset_0_1px_0_0_color-mix(in_srgb,var(--foreground)_8%,transparent)]">
          <div
            className="pointer-events-none absolute inset-x-0 top-0 z-10 h-16 bg-[linear-gradient(to_bottom,color-mix(in_srgb,var(--foreground)_10%,transparent),transparent)]"
            aria-hidden
          />

          <div className="aspect-video w-full max-h-[min(75dvh,calc(92vw*9/16))]">
            {open ? (
              <iframe
                key={playerKey}
                src={withAutoplay(embedSrc)}
                title={dialogTitle}
                className="size-full"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowFullScreen
              />
            ) : null}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

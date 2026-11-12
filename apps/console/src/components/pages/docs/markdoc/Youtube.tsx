'use client'

import { Play } from 'lucide-react'
import { useState } from 'react'
import { YoutubePlayerDialog } from '@/components/global/shared/YoutubePlayerDialog'

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

export function MarkdocYoutube({ src, thumbnail, id, title }: MarkdocYoutubeProps) {
  const [open, setOpen] = useState(false)

  const embedSrc = resolveEmbedSrc(src, id)
  const thumbnailSrc = resolveThumbnail(thumbnail, id)

  if (!embedSrc) return null

  const dialogTitle = title?.trim() || 'YouTube video'

  return (
    <YoutubePlayerDialog
      open={open}
      onOpenChange={setOpen}
      embed={embedSrc}
      title={dialogTitle}
    >
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
    </YoutubePlayerDialog>
  )
}

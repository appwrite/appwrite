import { useCallback, useState, type ReactNode } from 'react'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

type YoutubePlayerDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  embed: string | null
  title?: string
  /** Rendered inside the dialog root, for triggers that live next to the player. */
  children?: ReactNode
}

export function withAutoplay(embedSrc: string): string {
  try {
    const url = new URL(embedSrc)
    url.searchParams.set('autoplay', '1')
    return url.toString()
  } catch {
    const separator = embedSrc.includes('?') ? '&' : '?'
    return `${embedSrc}${separator}autoplay=1`
  }
}

export function YoutubePlayerDialog({
  open,
  onOpenChange,
  embed,
  title,
  children,
}: YoutubePlayerDialogProps) {
  const [playerKey, setPlayerKey] = useState(0)

  const handleOpenChange = useCallback(
    (nextOpen: boolean) => {
      onOpenChange(nextOpen)
      if (!nextOpen) {
        window.setTimeout(() => {
          setPlayerKey((current) => current + 1)
        }, 200)
      }
    },
    [onOpenChange],
  )

  const dialogTitle = title?.trim() || 'YouTube video'

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      {children}

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
            {open && embed ? (
              <iframe
                key={playerKey}
                src={withAutoplay(embed)}
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

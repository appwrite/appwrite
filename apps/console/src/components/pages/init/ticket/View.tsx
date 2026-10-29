import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { getInitTicketShareImageSrc } from '@/lib/init/init-ticket-share'
import {
  INIT_TICKET_ASPECT_RATIO,
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'
import { cn } from '@/lib/utils'

type InitTicketShareViewProps = {
  ticketId: string
  imageSrc?: string
}

export function View({ ticketId, imageSrc: imageSrcFromLoader }: InitTicketShareViewProps) {
  const imageSrc = imageSrcFromLoader ?? getInitTicketShareImageSrc(ticketId)
  const [imageLoaded, setImageLoaded] = useState(false)

  return (
    <div className="mx-auto w-full max-w-[820px] px-4 py-10 sm:px-6 sm:py-12">
      <div className="space-y-6 text-center">
        <div
          className="relative w-full overflow-hidden rounded-xl border border-border bg-muted/30 shadow-sm"
          style={{ aspectRatio: INIT_TICKET_ASPECT_RATIO }}
        >
          <img
            src={imageSrc}
            alt="Init ticket"
            width={INIT_TICKET_IMAGE_WIDTH}
            height={INIT_TICKET_IMAGE_HEIGHT}
            decoding="async"
            fetchPriority="high"
            loading="eager"
            onLoad={() => setImageLoaded(true)}
            className={cn(
              'absolute inset-0 h-full w-full object-cover transition-opacity duration-200',
              imageLoaded ? 'opacity-100' : 'opacity-0',
            )}
          />
        </div>
        <div className="space-y-3">
          <h1 className="text-[20px] font-semibold tracking-tight text-foreground sm:text-[22px]">
            Init ticket
          </h1>
          <p className="text-[13px] leading-relaxed text-muted-foreground">
            Claim your personalized pass, customize it with your stack, and share
            for a chance to win exclusive Init swag.
          </p>
          <Button asChild className="h-10 text-[13px]">
            <Link to="/init">Claim your ticket</Link>
          </Button>
        </div>
      </div>
    </div>
  )
}

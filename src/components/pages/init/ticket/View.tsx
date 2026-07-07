import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { getInitTicketShareImageSrc } from '@/lib/init/init-ticket-share'
import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'

type InitTicketShareViewProps = {
  ticketId: string
}

export function View({ ticketId }: InitTicketShareViewProps) {
  const imageSrc = getInitTicketShareImageSrc(ticketId)

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-10 sm:px-6">
      <div className="w-full max-w-[820px] space-y-6 text-center">
        <div className="overflow-hidden rounded-xl border border-border bg-card/40 shadow-sm">
          <img
            src={imageSrc}
            alt="Init ticket"
            width={INIT_TICKET_IMAGE_WIDTH}
            height={INIT_TICKET_IMAGE_HEIGHT}
            className="h-auto w-full"
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

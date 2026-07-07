import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

export function buildInitTicketImageSignature(
  data: InitTicketRenderData,
): string {
  return JSON.stringify({
    holderName: data.holderName,
    githubUsername: data.githubUsername ?? null,
    stack: data.prefs.stack,
    holderTitle: data.prefs.holderTitle ?? null,
    ticketTypeId: data.ticketAppearance.typeId,
    usesDarkChrome: data.ticketAppearance.usesDarkChrome,
    ticketNumber: data.ticketNumber,
  })
}

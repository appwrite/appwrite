import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

export function buildInitTicketImageUrl(params: {
  eventSlug: string
  renderData: InitTicketRenderData
  themeUsesDarkImage: boolean
  consoleUserId?: string
}): string {
  const { renderData } = params
  const search = new URLSearchParams()

  search.set('name', renderData.holderName)
  if (renderData.prefs.holderTitle?.trim()) {
    search.set('title', renderData.prefs.holderTitle.trim())
  }
  if (renderData.prefs.stack.length > 0) {
    search.set('stack', renderData.prefs.stack.join(','))
  }
  search.set('type', renderData.ticketAppearance.typeId)
  if (params.themeUsesDarkImage) {
    search.set('theme', 'dark')
  }
  search.set('ticketNumber', renderData.ticketNumber)
  if (params.consoleUserId) {
    search.set('userId', params.consoleUserId)
  }
  if (renderData.githubUsername) {
    search.set('github', renderData.githubUsername)
  }

  return `/init/ticket/${params.eventSlug}?${search.toString()}`
}

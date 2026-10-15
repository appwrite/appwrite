import { AsyncLocalStorage } from 'node:async_hooks'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'

type CoverRenderContext = {
  siteOrigin: string
}

const coverRenderContext = new AsyncLocalStorage<CoverRenderContext>()

export function getCoverRenderSiteOrigin(): string {
  return coverRenderContext.getStore()?.siteOrigin ?? getRequestSiteOrigin()
}

export function runWithCoverRenderContext<T>(
  siteOrigin: string,
  fn: () => T | Promise<T>,
): T | Promise<T> {
  return coverRenderContext.run({ siteOrigin }, fn)
}

import { createMiddleware } from '@tanstack/react-start'
import {
  createServerFnRejectionResponse,
  getServerFnRequestRejection,
  hardenServerFnResponse,
} from '@/lib/server-fn-guard'

/**
 * Only TanStack RPC calls may reach `/_serverFn/*`, browser navigations are
 * refused, and every server-function response is sandboxed so it can never be
 * rendered as a document on our origin. See `@/lib/server-fn-guard`.
 */
export const serverFnGuardMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, handlerType, next }) => {
  if (handlerType !== 'serverFn') {
    return next()
  }

  if (getServerFnRequestRejection(request)) {
    return createServerFnRejectionResponse()
  }

  const result = await next()
  return {
    ...result,
    response: hardenServerFnResponse(result.response),
  }
})

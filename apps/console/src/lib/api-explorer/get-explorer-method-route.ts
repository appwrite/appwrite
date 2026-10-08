export function getExplorerMethodRoute(input: {
  projectId: string
  serviceId: string
  operationId: string
}) {
  return {
    to: '/projects/$projectId/explorer',
    params: { projectId: input.projectId },
    search: {
      service: input.serviceId,
      operation: input.operationId,
    },
  } as const
}

export function getExplorerMethodLinkUrl(input: {
  projectId: string
  serviceId: string
  operationId: string
}): string {
  const url = new URL(window.location.origin)
  url.pathname = `/projects/${input.projectId}/explorer`
  url.searchParams.set('service', input.serviceId)
  url.searchParams.set('operation', input.operationId)
  return url.toString()
}

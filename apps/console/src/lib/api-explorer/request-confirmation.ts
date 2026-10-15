import type { ApiExplorerMethod } from './types'

export type SendRequestConfirmationCopy = {
  title: string
  description: string
  confirmVariant: 'default' | 'destructive'
}

export function methodRequiresSendConfirmation(
  method: ApiExplorerMethod,
): boolean {
  const httpMethod = method.httpMethod.toLowerCase()
  if (
    httpMethod === 'delete' ||
    httpMethod === 'put' ||
    httpMethod === 'patch'
  ) {
    return true
  }

  if (httpMethod !== 'post') return false

  const operationId = method.operationId.toLowerCase()
  const summary = method.summary.trim().toLowerCase()
  return (
    operationId.includes('update') ||
    summary.startsWith('update ')
  )
}

export function getSendRequestConfirmationCopy(
  method: ApiExplorerMethod,
): SendRequestConfirmationCopy {
  const httpMethod = method.httpMethod.toLowerCase()

  if (httpMethod === 'delete') {
    return {
      title: 'Send delete request',
      description: `This will call the live API for "${method.summary}" and may permanently delete data. This action cannot be undone.`,
      confirmVariant: 'destructive',
    }
  }

  return {
    title: 'Send update request',
    description: `This will call the live API for "${method.summary}" and may modify data in your project.`,
    confirmVariant: 'default',
  }
}

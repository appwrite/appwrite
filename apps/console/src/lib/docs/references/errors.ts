export class ReferenceNotFoundError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ReferenceNotFoundError'
  }
}

export function isReferenceNotFoundError(
  error: unknown,
): error is ReferenceNotFoundError {
  return error instanceof ReferenceNotFoundError
}

export function isApiReferenceNotFoundError(error: unknown): boolean {
  return error instanceof Error && error.message === 'API_REFERENCE_NOT_FOUND'
}

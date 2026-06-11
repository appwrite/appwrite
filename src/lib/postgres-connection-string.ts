const MASKED_PASSWORD = '••••••••'

/**
 * Returns a DSN suitable for display, with the password segment hidden.
 * The original connection string should still be used for copy/actions.
 */
export function maskPostgresConnectionStringPassword(
  connectionString: string,
  password?: string,
): string {
  if (password) {
    if (connectionString.includes(`:${password}@`)) {
      return connectionString.replace(`:${password}@`, `:${MASKED_PASSWORD}@`)
    }

    const encodedPassword = encodeURIComponent(password)
    if (
      encodedPassword !== password &&
      connectionString.includes(`:${encodedPassword}@`)
    ) {
      return connectionString.replace(
        `:${encodedPassword}@`,
        `:${MASKED_PASSWORD}@`,
      )
    }
  }

  try {
    const parsed = new URL(connectionString)
    if (!parsed.password) return connectionString
    parsed.password = MASKED_PASSWORD
    return parsed.toString()
  } catch {
    return connectionString.replace(
      /^(postgres(?:ql)?(?:\+[\w-]+)?:\/\/[^:/@\s]+:)([^@\s/]+)(@)/i,
      `$1${MASKED_PASSWORD}$3`,
    )
  }
}

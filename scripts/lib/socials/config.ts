export function requireEnv(name: string): string {
  const value = process.env[name]?.trim()
  if (!value) {
    throw new Error(`${name} is required. Set it in .env (see .env.example).`)
  }
  return value
}

export function optionalEnv(name: string): string | undefined {
  const value = process.env[name]?.trim()
  return value || undefined
}

export const INIT_TICKET_STORAGE_DEFAULT_PROJECT_ID = '659868b10fff07726b85'
export const INIT_TICKET_STORAGE_DEFAULT_BUCKET_ID = 'tickets'
export const INIT_TICKET_STORAGE_DEFAULT_ENDPOINT =
  'https://fra.cloud.appwrite.io/v1'

type EnvRecord = Record<string, string | undefined>

export type InitTicketStorageConfig = {
  endpoint: string
  projectId: string
  bucketId: string
}

function readEnvValue(env: EnvRecord, key: string): string {
  return (env[key] ?? '').toString().trim()
}

export function readInitTicketStorageConfigFromEnv(
  env: EnvRecord,
): InitTicketStorageConfig {
  return {
    endpoint:
      readEnvValue(env, 'VITE_INIT_TICKET_STORAGE_ENDPOINT') ||
      readEnvValue(env, 'INIT_TICKET_STORAGE_ENDPOINT') ||
      INIT_TICKET_STORAGE_DEFAULT_ENDPOINT,
    projectId:
      readEnvValue(env, 'VITE_INIT_TICKET_STORAGE_PROJECT_ID') ||
      readEnvValue(env, 'INIT_TICKET_STORAGE_PROJECT_ID') ||
      INIT_TICKET_STORAGE_DEFAULT_PROJECT_ID,
    bucketId:
      readEnvValue(env, 'VITE_INIT_TICKET_STORAGE_BUCKET_ID') ||
      readEnvValue(env, 'INIT_TICKET_STORAGE_BUCKET_ID') ||
      INIT_TICKET_STORAGE_DEFAULT_BUCKET_ID,
  }
}

export function getInitTicketStorageConfig(): InitTicketStorageConfig {
  if (typeof import.meta !== 'undefined' && import.meta.env) {
    return readInitTicketStorageConfigFromEnv(
      import.meta.env as unknown as EnvRecord,
    )
  }
  return readInitTicketStorageConfigFromEnv(process.env as EnvRecord)
}

export function isInitTicketStorageConfigured(): boolean {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  return Boolean(endpoint && projectId && bucketId)
}

const INIT_TICKET_ID_MAX_LENGTH = 36

/** An Appwrite file id: letters, digits, `.`, `-` and `_`, starting with a letter or digit. */
export function isInitTicketId(value: string): boolean {
  if (!value || value.length > INIT_TICKET_ID_MAX_LENGTH) return false
  for (let index = 0; index < value.length; index++) {
    const character = value[index] as string
    const isAlphanumeric =
      (character >= 'a' && character <= 'z') ||
      (character >= 'A' && character <= 'Z') ||
      (character >= '0' && character <= '9')
    if (isAlphanumeric) continue
    if (index === 0 || !['.', '-', '_'].includes(character)) return false
  }
  return true
}

export function getInitTicketStorageFileUrl(fileId: string): string {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  const params = new URLSearchParams({ project: projectId })
  return `${endpoint}/storage/buckets/${encodeURIComponent(bucketId)}/files/${encodeURIComponent(fileId)}?${params.toString()}`
}

export function getInitTicketStorageFileViewUrl(fileId: string): string {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  const params = new URLSearchParams({ project: projectId })
  return `${endpoint}/storage/buckets/${encodeURIComponent(bucketId)}/files/${encodeURIComponent(fileId)}/view?${params.toString()}`
}

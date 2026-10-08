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

export function getInitTicketStorageFileViewUrl(fileId: string): string {
  const { endpoint, projectId, bucketId } = getInitTicketStorageConfig()
  const params = new URLSearchParams({ project: projectId })
  return `${endpoint}/storage/buckets/${bucketId}/files/${fileId}/view?${params.toString()}`
}

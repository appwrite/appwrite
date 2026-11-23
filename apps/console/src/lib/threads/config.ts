export type ThreadsAppwriteConfig = {
  endpoint: string
  projectId: string
  databaseId: string
  threadsCollectionId: string
  messagesCollectionId: string
  authorsCollectionId: string
}

export function getThreadsAppwriteConfig(): ThreadsAppwriteConfig {
  return {
    endpoint:
      import.meta.env.VITE_THREADS_APPWRITE_ENDPOINT ??
      'https://fra.cloud.appwrite.io/v1',
    projectId:
      import.meta.env.VITE_THREADS_APPWRITE_PROJECT_ID ?? '656dd556812fe2e5f4ed',
    databaseId: import.meta.env.VITE_THREADS_DB_ID ?? 'main',
    threadsCollectionId:
      import.meta.env.VITE_THREADS_COL_THREADS_ID ?? 'threads',
    messagesCollectionId:
      import.meta.env.VITE_THREADS_COL_MESSAGES_ID ?? 'messages',
    authorsCollectionId:
      import.meta.env.VITE_THREADS_COL_AUTHORS_ID ?? 'authors',
  }
}

export function isThreadsConfigured(): boolean {
  const { endpoint, projectId } = getThreadsAppwriteConfig()
  return Boolean(endpoint && projectId)
}

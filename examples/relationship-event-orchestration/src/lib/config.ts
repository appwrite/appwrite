import 'dotenv/config';

function required(key: string): string {
  const value = process.env[key];
  if (!value || value.trim() === '') {
    throw new Error(`Missing required env var ${key}`);
  }
  return value;
}

export const config = {
  endpoint: required('APPWRITE_ENDPOINT'),
  projectId: required('APPWRITE_PROJECT_ID'),
  apiKey: required('APPWRITE_API_KEY'),
  databaseId: process.env.APPWRITE_DATABASE_ID ?? 'app',
  collectionA: process.env.APPWRITE_COLLECTION_A ?? 'parents',
  collectionB: process.env.APPWRITE_COLLECTION_B ?? 'children',
  idempotencyTtlSeconds: Number.parseInt(
    process.env.IDEMPOTENCY_TTL_SECONDS ?? '86400',
    10,
  ),
} as const;

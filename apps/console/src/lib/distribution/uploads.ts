import { useMemo, useSyncExternalStore } from 'react'
import { Client, ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export const ARTIFACT_EXTENSIONS = ['.ipa', '.aab'] as const

export type ArtifactUploadStatus = 'uploading' | 'completed' | 'failed'

export type ArtifactUpload = {
  id: string
  projectId: string
  appId: string
  fileName: string
  size: number
  chunked: boolean
  progress: number
  status: ArtifactUploadStatus
  error?: string
  artifactId?: string
}

export type ArtifactUploadRefresh = {
  appId: string
  artifactId: string
  completed: boolean
}

type PendingRefresh = ArtifactUploadRefresh & { projectId: string }

const NO_UPLOADS: readonly ArtifactUpload[] = []

let uploads: readonly ArtifactUpload[] = NO_UPLOADS
let refreshes: readonly PendingRefresh[] = []
const files = new Map<string, File>()
const attempts = new Map<string, symbol>()
const listeners = new Set<() => void>()

function publish(next: readonly ArtifactUpload[]): void {
  uploads = next
  listeners.forEach((listener) => listener())
}

function find(id: string): ArtifactUpload | undefined {
  return uploads.find((upload) => upload.id === id)
}

function patch(id: string, changes: Partial<ArtifactUpload>): void {
  if (!find(id)) return
  publish(
    uploads.map((upload) =>
      upload.id === id ? { ...upload, ...changes } : upload,
    ),
  )
}

function queueRefresh(
  upload: ArtifactUpload,
  artifactId: string,
  completed: boolean,
): void {
  refreshes = [
    ...refreshes,
    { projectId: upload.projectId, appId: upload.appId, artifactId, completed },
  ]
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function snapshot(): readonly ArtifactUpload[] {
  return uploads
}

function serverSnapshot(): readonly ArtifactUpload[] {
  return NO_UPLOADS
}

function send(upload: ArtifactUpload, file: File, artifactId?: string): void {
  const attempt = Symbol(upload.id)
  attempts.set(upload.id, attempt)
  const current = () => attempts.get(upload.id) === attempt

  sdk
    .forProject(upload.projectId)
    .distribution.createArtifact({
      appId: upload.appId,
      file,
      artifactId,
      onProgress: ({ $id, progress }) => {
        if (!current()) throw new Error('Artifact upload cancelled')
        if ($id && !find(upload.id)?.artifactId) {
          queueRefresh(upload, $id, false)
        }
        patch(upload.id, {
          progress: Math.min(progress, 99),
          ...($id ? { artifactId: $id } : {}),
        })
      },
    })
    .then((artifact) => {
      if (!current()) return
      attempts.delete(upload.id)
      files.delete(upload.id)
      queueRefresh(upload, artifact.$id, true)
      patch(upload.id, {
        status: 'completed',
        progress: 100,
        artifactId: artifact.$id,
      })
    })
    .catch((error: unknown) => {
      if (!current()) return
      attempts.delete(upload.id)
      patch(upload.id, {
        status: 'failed',
        error: getErrorMessage(error, ''),
      })
    })
}

export function isArtifactFile(name: string): boolean {
  const lower = name.toLowerCase()
  return ARTIFACT_EXTENSIONS.some(
    (extension) => lower.endsWith(extension) && lower.length > extension.length,
  )
}

export function startArtifactUpload(
  projectId: string,
  appId: string,
  file: File,
): ArtifactUpload {
  const upload: ArtifactUpload = {
    id: ID.unique(),
    projectId,
    appId,
    fileName: file.name,
    size: file.size,
    chunked: file.size > Client.CHUNK_SIZE,
    progress: 0,
    status: 'uploading',
  }
  files.set(upload.id, file)
  publish([upload, ...uploads])
  send(upload, file)
  return upload
}

export function retryArtifactUpload(id: string): void {
  const upload = find(id)
  const file = files.get(id)
  if (upload?.status !== 'failed' || !file) return
  patch(id, {
    status: 'uploading',
    progress: 0,
    error: undefined,
  })
  send(upload, file, upload.artifactId)
}

export function dismissArtifactUpload(id: string): void {
  attempts.delete(id)
  files.delete(id)
  if (!find(id)) return
  publish(uploads.filter((upload) => upload.id !== id))
}

export function cancelArtifactUpload(id: string): void {
  if (find(id)?.status !== 'uploading') return
  dismissArtifactUpload(id)
}

export function takeArtifactUploadRefreshes(
  projectId: string,
): ArtifactUploadRefresh[] {
  const taken = refreshes.filter((refresh) => refresh.projectId === projectId)
  if (taken.length === 0) return []
  refreshes = refreshes.filter((refresh) => refresh.projectId !== projectId)
  return taken.map(({ appId, artifactId, completed }) => ({
    appId,
    artifactId,
    completed,
  }))
}

export function useArtifactUploads(
  projectId: string | null | undefined,
): ArtifactUpload[] {
  const all = useSyncExternalStore(subscribe, snapshot, serverSnapshot)
  return useMemo(
    () => all.filter((upload) => upload.projectId === projectId),
    [all, projectId],
  )
}

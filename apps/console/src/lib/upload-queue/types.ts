/**
 * Types for background file upload queue system
 */

export type UploadStatus =
  | 'pending'
  | 'uploading'
  | 'completed'
  | 'failed'
  | 'cancelled'

export interface UploadItem {
  id: string
  projectId: string
  bucketId: string
  fileId?: string
  fileName: string
  fileSize: number
  fileType: string
  fileData: ArrayBuffer | File | Blob
  permissions?: string[]
  status: UploadStatus
  progress: number
  error?: string
  createdAt: number
  updatedAt: number
  startedAt?: number
  completedAt?: number
  retryCount?: number
  maxRetries?: number
}

export interface UploadProgress {
  id: string
  status: UploadStatus
  progress: number
  error?: string
}

export type UploadProgressCallback = (progress: UploadProgress) => void

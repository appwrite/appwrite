import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { registerDom, unregisterDom } from './dom.ts'
import { AppwriteException, type UploadProgress } from '@appwrite.io/console'
import * as actualSdk from '@/lib/appwrite/sdk'
import type { DistributionArtifact } from '@/lib/appwrite/distribution'

registerDom({ url: 'http://localhost/' })
const { act, cleanup, renderHook } = await import('@testing-library/react')

type Upload = {
  projectId: string
  appId: string
  file: File
  artifactId?: string
  onProgress: (progress: UploadProgress) => void
  resolve: (artifact: Pick<DistributionArtifact, '$id'>) => void
  reject: (error: unknown) => void
}

const realSdk = { ...actualSdk }
const sent: Upload[] = []

mock.module('@/lib/appwrite/sdk', () => ({
  ...actualSdk,
  sdk: {
    forProject: (projectId: string) => ({
      distribution: {
        createArtifact: ({
          appId,
          file,
          artifactId,
          onProgress,
        }: Pick<Upload, 'appId' | 'file' | 'artifactId' | 'onProgress'>) =>
          new Promise((resolve, reject) => {
            sent.push({
              projectId,
              appId,
              file,
              artifactId,
              onProgress,
              resolve,
              reject,
            })
          }),
      },
    }),
  },
}))

const {
  cancelArtifactUpload,
  dismissArtifactUpload,
  isArtifactFile,
  retryArtifactUpload,
  startArtifactUpload,
  takeArtifactUploadRefreshes,
  useArtifactUploads,
} = await import('@/lib/distribution/uploads')

const started: string[] = []

function start(projectId: string, name: string, size = 11_534_336) {
  const file = new File([new Uint8Array(1)], name)
  Object.defineProperty(file, 'size', { value: size })
  const upload = startArtifactUpload(projectId, 'app1', file)
  started.push(upload.id)
  return { upload, request: sent[sent.length - 1] }
}

function progress(id: string, value: number): UploadProgress {
  return {
    $id: id,
    progress: value,
    sizeUploaded: 0,
    chunksTotal: 3,
    chunksUploaded: 1,
  }
}

afterEach(() => {
  act(() => started.splice(0).forEach(dismissArtifactUpload))
  sent.splice(0)
  takeArtifactUploadRefreshes('project1')
  takeArtifactUploadRefreshes('project2')
  cleanup()
})

afterAll(async () => {
  mock.module('@/lib/appwrite/sdk', () => realSdk)
  await unregisterDom()
})

describe('isArtifactFile', () => {
  test.each([
    ['Runner.ipa', true],
    ['app-release.AAB', true],
    ['build.v2.aab', true],
    ['.ipa', false],
    ['app.apk', false],
    ['ipa', false],
    ['notes.ipa.txt', false],
  ])('%s is %p', (name, expected) => {
    expect(isArtifactFile(name)).toBe(expected)
  })
})

describe('artifact uploads', () => {
  test('sends the file to the app of its project', () => {
    const { upload, request } = start('project1', 'shop.aab')

    expect(request.projectId).toBe('project1')
    expect(request.appId).toBe('app1')
    expect(request.file.name).toBe('shop.aab')
    expect(upload).toMatchObject({
      projectId: 'project1',
      appId: 'app1',
      fileName: 'shop.aab',
      size: 11_534_336,
      chunked: true,
      progress: 0,
      status: 'uploading',
    })
  })

  test('marks a file that fits one request as not chunked', () => {
    const { upload } = start('project1', 'small.ipa', 1_048_576)

    expect(upload.chunked).toBe(false)
  })

  test('tracks progress until the last chunk answers', async () => {
    const { upload, request } = start('project1', 'shop.aab')
    const { result } = renderHook(() => useArtifactUploads('project1'))

    act(() => request.onProgress(progress('artifact1', 45)))
    expect(result.current[0]).toMatchObject({
      progress: 45,
      artifactId: 'artifact1',
      status: 'uploading',
    })

    act(() => request.onProgress(progress('artifact1', 100)))
    expect(result.current[0].progress).toBe(99)
    expect(result.current[0].status).toBe('uploading')

    await act(async () => request.resolve({ $id: 'artifact1' }))
    expect(result.current).toEqual([
      expect.objectContaining({
        id: upload.id,
        progress: 100,
        status: 'completed',
        artifactId: 'artifact1',
      }),
    ])
  })

  test('keeps the server message when the upload fails', async () => {
    const { request } = start('project1', 'shop.ipa')
    const { result } = renderHook(() => useArtifactUploads('project1'))

    await act(async () =>
      request.reject(
        new AppwriteException(
          'Only ipa and aab files can be uploaded.',
          400,
          'storage_file_type_unsupported',
        ),
      ),
    )

    expect(result.current[0]).toMatchObject({
      status: 'failed',
      error: 'Only ipa and aab files can be uploaded.',
    })
  })

  test('lists only the uploads of the project, newest first', () => {
    start('project1', 'first.aab')
    start('project2', 'other.aab')
    start('project1', 'second.ipa')
    const { result } = renderHook(() => useArtifactUploads('project1'))

    expect(result.current.map((upload) => upload.fileName)).toEqual([
      'second.ipa',
      'first.aab',
    ])
  })

  test('dismisses one upload and aborts its remaining chunks', () => {
    const { upload, request } = start('project1', 'shop.aab')
    start('project1', 'kept.ipa')
    const { result } = renderHook(() => useArtifactUploads('project1'))

    act(() => dismissArtifactUpload(upload.id))

    expect(() => request.onProgress(progress('artifact1', 45))).toThrow()
    expect(result.current.map((item) => item.fileName)).toEqual(['kept.ipa'])
  })

  test('cancels an upload in flight and ignores its late answer', async () => {
    const { upload, request } = start('project1', 'shop.aab')
    const { result } = renderHook(() => useArtifactUploads('project1'))
    act(() => request.onProgress(progress('artifact1', 45)))
    takeArtifactUploadRefreshes('project1')

    act(() => cancelArtifactUpload(upload.id))
    expect(() => request.onProgress(progress('artifact1', 91))).toThrow()
    await act(async () => request.resolve({ $id: 'artifact1' }))

    expect(result.current).toEqual([])
    expect(takeArtifactUploadRefreshes('project1')).toEqual([])
  })

  test('does not cancel an upload that already finished', async () => {
    const { upload, request } = start('project1', 'shop.aab')
    const { result } = renderHook(() => useArtifactUploads('project1'))
    await act(async () => request.resolve({ $id: 'artifact1' }))

    act(() => cancelArtifactUpload(upload.id))

    expect(result.current[0].status).toBe('completed')
  })

  test('resumes a failed upload under the artifact the server created', async () => {
    const { upload, request } = start('project1', 'shop.aab')
    const { result } = renderHook(() => useArtifactUploads('project1'))
    act(() => request.onProgress(progress('artifact1', 45)))
    await act(async () =>
      request.reject(
        new AppwriteException(
          'Artifact upload is busy',
          429,
          'distribution_artifact_upload_busy',
        ),
      ),
    )
    expect(result.current[0]).toMatchObject({
      status: 'failed',
      error: 'Artifact upload is busy',
    })

    act(() => retryArtifactUpload(upload.id))

    expect(sent).toHaveLength(2)
    const retry = sent[1]
    expect(request.artifactId).toBeUndefined()
    expect(retry.file).toBe(request.file)
    expect(retry.appId).toBe('app1')
    expect(retry.artifactId).toBe('artifact1')
    expect(result.current[0]).toMatchObject({
      id: upload.id,
      status: 'uploading',
      progress: 0,
      error: undefined,
      artifactId: 'artifact1',
    })

    await act(async () => retry.resolve({ $id: 'artifact1' }))
    expect(result.current[0]).toMatchObject({
      status: 'completed',
      artifactId: 'artifact1',
    })
  })

  test('restarts a failed upload the server never created', async () => {
    const { upload, request } = start('project1', 'shop.aab')
    const { result } = renderHook(() => useArtifactUploads('project1'))
    await act(async () =>
      request.reject(
        new AppwriteException(
          'Artifact upload is busy',
          429,
          'distribution_artifact_upload_busy',
        ),
      ),
    )

    act(() => retryArtifactUpload(upload.id))

    expect(sent).toHaveLength(2)
    expect(sent[1].artifactId).toBeUndefined()
    expect(result.current[0].status).toBe('uploading')
    expect(result.current[0].artifactId).toBeUndefined()
  })

  test('only retries a failed upload', () => {
    const { upload } = start('project1', 'shop.aab')

    retryArtifactUpload(upload.id)

    expect(sent).toHaveLength(1)
  })

  test('queues list refreshes for the created and completed artifact until taken', async () => {
    const { request } = start('project1', 'shop.aab')
    start('project2', 'other.aab')

    act(() => request.onProgress(progress('artifact1', 45)))
    act(() => request.onProgress(progress('artifact1', 91)))
    await act(async () => request.resolve({ $id: 'artifact1' }))

    expect(takeArtifactUploadRefreshes('project2')).toEqual([])
    expect(takeArtifactUploadRefreshes('project1')).toEqual([
      { appId: 'app1', artifactId: 'artifact1', completed: false },
      { appId: 'app1', artifactId: 'artifact1', completed: true },
    ])
    expect(takeArtifactUploadRefreshes('project1')).toEqual([])
  })
})

/**
 * Hand-written Videos client: the videos API has not shipped in a Console SDK
 * release yet, so the pinned SDK carries no `Videos` service. Copied from the code the
 * SDK generator produces for the `/videos` API; delete this folder for the generated one once it ships.
 */

import {
  AppwriteException,
  Client,
  type ImageFormat,
  type Payload,
} from '@appwrite.io/console'
import type { VideoOutput, VideoRenditionStatus } from './enums'
import type {
  Video,
  VideoCaption,
  VideoCaptionList,
  VideoCodecList,
  VideoList,
  VideoProfile,
  VideoProfileList,
  VideoRendition,
  VideoRenditionList,
} from './models'

type DefaultsShape = string | { items: DefaultsShape }

type DefaultsModel = {
  defaults?: Record<string, unknown>
  properties?: Record<string, DefaultsShape>
}

type Defaults = {
  root: DefaultsShape
  models: Record<string, DefaultsModel>
}

function applyDefaults<T>(value: T, tree: Defaults): T {
  return fillDefaults(value, tree.root, tree.models) as T
}

function cloneDefault(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(cloneDefault)
  }
  if (typeof value === 'object' && value !== null) {
    const copy: Record<string, unknown> = {}
    for (const [key, member] of Object.entries(value)) {
      copy[key] = cloneDefault(member)
    }
    return copy
  }
  return value
}

function fillDefaults(
  value: unknown,
  shape: DefaultsShape,
  models: Record<string, DefaultsModel>,
): unknown {
  if (typeof shape !== 'string') {
    if (!Array.isArray(value)) {
      return value
    }
    return value.map((item) => fillDefaults(item, shape.items, models))
  }
  const model = models[shape]
  if (
    !model ||
    typeof value !== 'object' ||
    value === null ||
    Array.isArray(value)
  ) {
    return value
  }
  const result: Record<string, unknown> = { ...model.defaults, ...value }
  for (const key of Object.keys(model.defaults ?? {})) {
    if (!Object.prototype.hasOwnProperty.call(value, key)) {
      result[key] = cloneDefault(result[key])
    }
  }
  for (const [key, child] of Object.entries(model.properties ?? {})) {
    if (key in result) {
      result[key] = fillDefaults(result[key], child, models)
    }
  }
  return result
}

function encodePath(value: unknown): string {
  const segment = String(value)
  if (segment === '.' || segment === '..') {
    throw new AppwriteException('Path parameter cannot be "." or ".."')
  }
  return encodeURIComponent(segment)
}

export class Videos {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /**
   * Get a list of videos in the current project. Sessions receive only videos they can read according to each video's `$permissions`. API keys and admins receive the full project catalog. Playback uses the same document read ACL plus the `videos.play` (or `videos.read`) scope: a guest can stream a video that grants `read("any")` or `read("guests")` even though guests lack `videos.read` and will not see it in this list.
   *
   *
   * @param {string[]} params.queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: bucketId, fileId, name, size, format, duration, width, height, videoCodec, videoBitRate, audioCodec, audioBitRate, search
   * @param {boolean} params.total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoList>}
   */
  list(params?: { queries?: string[]; total?: boolean }): Promise<VideoList>
  /**
   * Get a list of videos in the current project. Sessions receive only videos they can read according to each video's `$permissions`. API keys and admins receive the full project catalog. Playback uses the same document read ACL plus the `videos.play` (or `videos.read`) scope: a guest can stream a video that grants `read("any")` or `read("guests")` even though guests lack `videos.read` and will not see it in this list.
   *
   *
   * @param {string[]} queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: bucketId, fileId, name, size, format, duration, width, height, videoCodec, videoBitRate, audioCodec, audioBitRate, search
   * @param {boolean} total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  list(queries?: string[], total?: boolean): Promise<VideoList>
  list(
    paramsOrFirst?: { queries?: string[]; total?: boolean } | string[],
    ...rest: [boolean?]
  ): Promise<VideoList> {
    let params: { queries?: string[]; total?: boolean }

    if (
      (typeof paramsOrFirst === 'undefined' && rest.length === 0) ||
      (paramsOrFirst &&
        typeof paramsOrFirst === 'object' &&
        !Array.isArray(paramsOrFirst))
    ) {
      params = (paramsOrFirst || {}) as {
        queries?: string[]
        total?: boolean
      }
    } else {
      params = {
        queries: paramsOrFirst as string[],
        total: rest[0] as boolean,
      }
    }

    const queries = params.queries
    const total = params.total

    const apiPath = '/videos'
    const apiPayload: Payload = {}
    if (typeof queries !== 'undefined') {
      apiPayload['queries'] = queries
    }
    if (typeof total !== 'undefined') {
      apiPayload['total'] = total
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoList',
      models: {
        videoList: {
          defaults: {
            total: 0,
            videos: [],
          },
          properties: {
            videos: {
              items: 'video',
            },
          },
        },
        video: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            $permissions: [],
            bucketId: '',
            fileId: '',
            name: '',
            size: 0,
            format: '',
            duration: 0,
            width: 0,
            height: 0,
            aspectRatio: '',
            videoCodec: '',
            videoFormat: '',
            videoFormatProfile: '',
            videoBitRate: 0,
            videoFrameRate: '',
            videoFrameRateMode: '',
            audioCodec: '',
            audioFormat: '',
            audioBitRate: 0,
            audioSampleRate: '',
            timelineStatus: '',
            timelineError: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file, and the caller must be able to read that file. Creating a video stores the document and queues timeline generation. The worker downloads the source, probes metadata, extracts embedded captions once, and deletes its working copy when finished. Sources with a video track also get a thumbnail timeline. Call create-rendition to package a streaming output.
   *
   * An optional `name` defaults to the source file name. An optional `permissions` array sets the video document ACL (list, get, update, delete, manage child resources, and playback). By default, only the current user is granted all permissions; API keys and admins create with empty permissions when omitted. Guests with `videos.play` can stream when the video grants them read (`read("any")` or `read("guests")`).
   *
   * Uploaded caption files stay listed alongside auto-extracted tracks for the same language; when the current default is an auto-extracted track with that code, the upload takes the default flag.
   *
   *
   * @param {string} params.bucketId - Storage bucket unique ID holding the source file.
   * @param {string} params.fileId - Source file unique ID.
   * @param {string} params.name - Video name. Defaults to the source file name. Max length: 2048 chars.
   * @param {string[] | null} params.permissions - An array of permission strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  create(params: {
    bucketId: string
    fileId: string
    name?: string
    permissions?: string[] | null
  }): Promise<Video>
  /**
   * Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file, and the caller must be able to read that file. Creating a video stores the document and queues timeline generation. The worker downloads the source, probes metadata, extracts embedded captions once, and deletes its working copy when finished. Sources with a video track also get a thumbnail timeline. Call create-rendition to package a streaming output.
   *
   * An optional `name` defaults to the source file name. An optional `permissions` array sets the video document ACL (list, get, update, delete, manage child resources, and playback). By default, only the current user is granted all permissions; API keys and admins create with empty permissions when omitted. Guests with `videos.play` can stream when the video grants them read (`read("any")` or `read("guests")`).
   *
   * Uploaded caption files stay listed alongside auto-extracted tracks for the same language; when the current default is an auto-extracted track with that code, the upload takes the default flag.
   *
   *
   * @param {string} bucketId - Storage bucket unique ID holding the source file.
   * @param {string} fileId - Source file unique ID.
   * @param {string} name - Video name. Defaults to the source file name. Max length: 2048 chars.
   * @param {string[] | null} permissions - An array of permission strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  create(
    bucketId: string,
    fileId: string,
    name?: string,
    permissions?: string[] | null,
  ): Promise<Video>
  create(
    paramsOrFirst:
      | {
          bucketId: string
          fileId: string
          name?: string
          permissions?: string[] | null
        }
      | string,
    ...rest: [string?, string?, (string[] | null)?]
  ): Promise<Video> {
    let params: {
      bucketId: string
      fileId: string
      name?: string
      permissions?: string[] | null
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        bucketId: string
        fileId: string
        name?: string
        permissions?: string[] | null
      }
    } else {
      params = {
        bucketId: paramsOrFirst as string,
        fileId: rest[0] as string,
        name: rest[1] as string,
        permissions: rest[2] as string[] | null,
      }
    }

    const bucketId = params.bucketId
    const fileId = params.fileId
    const name = params.name
    const permissions = params.permissions

    if (typeof bucketId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "bucketId"')
    }
    if (typeof fileId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "fileId"')
    }
    const apiPath = '/videos'
    const apiPayload: Payload = {}
    if (typeof bucketId !== 'undefined') {
      apiPayload['bucketId'] = bucketId
    }
    if (typeof fileId !== 'undefined') {
      apiPayload['fileId'] = fileId
    }
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof permissions !== 'undefined') {
      apiPayload['permissions'] = permissions
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('post', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'video',
      models: {
        video: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            $permissions: [],
            bucketId: '',
            fileId: '',
            name: '',
            size: 0,
            format: '',
            duration: 0,
            width: 0,
            height: 0,
            aspectRatio: '',
            videoCodec: '',
            videoFormat: '',
            videoFormatProfile: '',
            videoBitRate: 0,
            videoFrameRate: '',
            videoFrameRateMode: '',
            audioCodec: '',
            audioFormat: '',
            audioBitRate: 0,
            audioSampleRate: '',
            timelineStatus: '',
            timelineError: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Get a list of video encode codecs enabled on this instance. Each codec includes the output formats it may be packaged into.
   *
   *
   * @throws {AppwriteException}
   * @returns {Promise<VideoCodecList>}
   */
  listCodecs(): Promise<VideoCodecList> {
    const apiPath = '/videos/codecs'
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoCodecList',
      models: {
        videoCodecList: {
          defaults: {
            total: 0,
            codecs: [],
          },
          properties: {
            codecs: {
              items: 'videoCodec',
            },
          },
        },
        videoCodec: {
          defaults: {
            $id: '',
            name: '',
            outputs: [],
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Get a video by its unique ID. This endpoint response returns a JSON object with the video metadata.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  get(params: { videoId: string }): Promise<Video>
  /**
   * Get a video by its unique ID. This endpoint response returns a JSON object with the video metadata.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  get(videoId: string): Promise<Video>
  get(paramsOrFirst: { videoId: string } | string): Promise<Video> {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'video',
      models: {
        video: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            $permissions: [],
            bucketId: '',
            fileId: '',
            name: '',
            size: 0,
            format: '',
            duration: 0,
            width: 0,
            height: 0,
            aspectRatio: '',
            videoCodec: '',
            videoFormat: '',
            videoFormatProfile: '',
            videoBitRate: 0,
            videoFrameRate: '',
            videoFrameRateMode: '',
            audioCodec: '',
            audioFormat: '',
            audioBitRate: 0,
            audioSampleRate: '',
            timelineStatus: '',
            timelineError: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Update a video's name and optional permissions. The caller must hold update permission on the video document. Omit `permissions` to keep the current ACL. The source file cannot be replaced; delete the video and create a new one to point at a different file.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.name - Video name. Max length: 2048 chars.
   * @param {string[] | null} params.permissions - An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  update(params: {
    videoId: string
    name: string
    permissions?: string[] | null
  }): Promise<Video>
  /**
   * Update a video's name and optional permissions. The caller must hold update permission on the video document. Omit `permissions` to keep the current ACL. The source file cannot be replaced; delete the video and create a new one to point at a different file.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} name - Video name. Max length: 2048 chars.
   * @param {string[] | null} permissions - An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  update(
    videoId: string,
    name: string,
    permissions?: string[] | null,
  ): Promise<Video>
  update(
    paramsOrFirst:
      | { videoId: string; name: string; permissions?: string[] | null }
      | string,
    ...rest: [string?, (string[] | null)?]
  ): Promise<Video> {
    let params: {
      videoId: string
      name: string
      permissions?: string[] | null
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        name: string
        permissions?: string[] | null
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        name: rest[0] as string,
        permissions: rest[1] as string[] | null,
      }
    }

    const videoId = params.videoId
    const name = params.name
    const permissions = params.permissions

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    const apiPath = '/videos/{videoId}'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof permissions !== 'undefined') {
      apiPayload['permissions'] = permissions
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('put', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'video',
      models: {
        video: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            $permissions: [],
            bucketId: '',
            fileId: '',
            name: '',
            size: 0,
            format: '',
            duration: 0,
            width: 0,
            height: 0,
            aspectRatio: '',
            videoCodec: '',
            videoFormat: '',
            videoFormatProfile: '',
            videoBitRate: 0,
            videoFrameRate: '',
            videoFrameRateMode: '',
            audioCodec: '',
            audioFormat: '',
            audioBitRate: 0,
            audioSampleRate: '',
            timelineStatus: '',
            timelineError: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Delete a video by its unique ID. This also removes every rendition, caption, preview and transcoded artifact derived from it. Fails with `video_rendition_in_progress` while a rendition is started or ended, unless that rendition has not been updated within the rendition retention window.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  delete(params: { videoId: string }): Promise<{}>
  /**
   * Delete a video by its unique ID. This also removes every rendition, caption, preview and transcoded artifact derived from it. Fails with `video_rendition_in_progress` while a rendition is started or ended, unless that rendition has not been updated within the rendition retention window.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  delete(videoId: string): Promise<{}>
  delete(paramsOrFirst: { videoId: string } | string): Promise<{}> {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('delete', uri, apiHeaders, apiPayload)
  }

  /**
   * Get a list of all the caption tracks attached to a video.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaptionList>}
   */
  listCaptions(params: { videoId: string }): Promise<VideoCaptionList>
  /**
   * Get a list of all the caption tracks attached to a video.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaptionList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  listCaptions(videoId: string): Promise<VideoCaptionList>
  listCaptions(
    paramsOrFirst: { videoId: string } | string,
  ): Promise<VideoCaptionList> {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/captions'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoCaptionList',
      models: {
        videoCaptionList: {
          defaults: {
            total: 0,
            captions: [],
          },
          properties: {
            captions: {
              items: 'videoCaption',
            },
          },
        },
        videoCaption: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            bucketId: '',
            fileId: '',
            name: '',
            code: '',
            default: false,
            embedded: false,
            targetDuration: '',
            status: '',
            error: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Add a caption track to a video from a WebVTT or SubRip file in a storage bucket. The file is normalised to WebVTT and segmented in the background. Subscribe to `videos.[videoId].captions.[captionId].update` for `started`, `ready`, and `error` status changes instead of only polling the caption document.
   *
   * Uploaded tracks are listed alongside auto-extracted embedded tracks (`embedded: true`). When the current default is an auto-extracted track for the same language code, the upload takes the default flag so players pick the authored file first. Auto-extracted tracks are never removed automatically — delete a track explicitly to remove it. Extraction runs once per video, so a deleted extracted track is not re-created.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.bucketId - Storage bucket unique ID holding the caption file.
   * @param {string} params.fileId - Caption file unique ID.
   * @param {string} params.name - Caption display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} params.code - Caption ISO 639-2 three-letter language code.
   * @param {boolean} params.xdefault - Make this the default caption track for the video.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaption>}
   */
  createCaption(params: {
    videoId: string
    bucketId: string
    fileId: string
    name: string
    code: string
    xdefault?: boolean
  }): Promise<VideoCaption>
  /**
   * Add a caption track to a video from a WebVTT or SubRip file in a storage bucket. The file is normalised to WebVTT and segmented in the background. Subscribe to `videos.[videoId].captions.[captionId].update` for `started`, `ready`, and `error` status changes instead of only polling the caption document.
   *
   * Uploaded tracks are listed alongside auto-extracted embedded tracks (`embedded: true`). When the current default is an auto-extracted track for the same language code, the upload takes the default flag so players pick the authored file first. Auto-extracted tracks are never removed automatically — delete a track explicitly to remove it. Extraction runs once per video, so a deleted extracted track is not re-created.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} bucketId - Storage bucket unique ID holding the caption file.
   * @param {string} fileId - Caption file unique ID.
   * @param {string} name - Caption display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} code - Caption ISO 639-2 three-letter language code.
   * @param {boolean} xdefault - Make this the default caption track for the video.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaption>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createCaption(
    videoId: string,
    bucketId: string,
    fileId: string,
    name: string,
    code: string,
    xdefault?: boolean,
  ): Promise<VideoCaption>
  createCaption(
    paramsOrFirst:
      | {
          videoId: string
          bucketId: string
          fileId: string
          name: string
          code: string
          xdefault?: boolean
        }
      | string,
    ...rest: [string?, string?, string?, string?, boolean?]
  ): Promise<VideoCaption> {
    let params: {
      videoId: string
      bucketId: string
      fileId: string
      name: string
      code: string
      xdefault?: boolean
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        bucketId: string
        fileId: string
        name: string
        code: string
        xdefault?: boolean
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        bucketId: rest[0] as string,
        fileId: rest[1] as string,
        name: rest[2] as string,
        code: rest[3] as string,
        xdefault: rest[4] as boolean,
      }
    }

    const videoId = params.videoId
    const bucketId = params.bucketId
    const fileId = params.fileId
    const name = params.name
    const code = params.code
    const xdefault = params.xdefault

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof bucketId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "bucketId"')
    }
    if (typeof fileId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "fileId"')
    }
    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    if (typeof code === 'undefined') {
      throw new AppwriteException('Missing required parameter: "code"')
    }
    const apiPath = '/videos/{videoId}/captions'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    if (typeof bucketId !== 'undefined') {
      apiPayload['bucketId'] = bucketId
    }
    if (typeof fileId !== 'undefined') {
      apiPayload['fileId'] = fileId
    }
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof code !== 'undefined') {
      apiPayload['code'] = code
    }
    if (typeof xdefault !== 'undefined') {
      apiPayload['default'] = xdefault
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('post', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoCaption',
      models: {
        videoCaption: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            bucketId: '',
            fileId: '',
            name: '',
            code: '',
            default: false,
            embedded: false,
            targetDuration: '',
            status: '',
            error: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Update a caption track by its unique ID. Pass only `name`, `code`, and/or `default` to retag an extracted track (for example `und` → `heb` for Hebrew) without replacing the file. Changing `bucketId`/`fileId` re-packages the track in the background.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.captionId - Caption unique ID.
   * @param {string} params.bucketId - Storage bucket unique ID holding the caption file. Omit together with fileId to only update name, code, or default.
   * @param {string} params.fileId - Caption file unique ID. Omit together with bucketId to only update name, code, or default.
   * @param {string} params.name - Caption display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} params.code - Caption ISO 639-2 three-letter language code (for example `heb` for Hebrew).
   * @param {boolean | null} params.xdefault - Make this the default caption track for the video. Omit to leave unchanged.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaption>}
   */
  updateCaption(params: {
    videoId: string
    captionId: string
    bucketId?: string
    fileId?: string
    name?: string
    code?: string
    xdefault?: boolean | null
  }): Promise<VideoCaption>
  /**
   * Update a caption track by its unique ID. Pass only `name`, `code`, and/or `default` to retag an extracted track (for example `und` → `heb` for Hebrew) without replacing the file. Changing `bucketId`/`fileId` re-packages the track in the background.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} captionId - Caption unique ID.
   * @param {string} bucketId - Storage bucket unique ID holding the caption file. Omit together with fileId to only update name, code, or default.
   * @param {string} fileId - Caption file unique ID. Omit together with bucketId to only update name, code, or default.
   * @param {string} name - Caption display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} code - Caption ISO 639-2 three-letter language code (for example `heb` for Hebrew).
   * @param {boolean | null} xdefault - Make this the default caption track for the video. Omit to leave unchanged.
   * @throws {AppwriteException}
   * @returns {Promise<VideoCaption>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  updateCaption(
    videoId: string,
    captionId: string,
    bucketId?: string,
    fileId?: string,
    name?: string,
    code?: string,
    xdefault?: boolean | null,
  ): Promise<VideoCaption>
  updateCaption(
    paramsOrFirst:
      | {
          videoId: string
          captionId: string
          bucketId?: string
          fileId?: string
          name?: string
          code?: string
          xdefault?: boolean | null
        }
      | string,
    ...rest: [string?, string?, string?, string?, string?, (boolean | null)?]
  ): Promise<VideoCaption> {
    let params: {
      videoId: string
      captionId: string
      bucketId?: string
      fileId?: string
      name?: string
      code?: string
      xdefault?: boolean | null
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        captionId: string
        bucketId?: string
        fileId?: string
        name?: string
        code?: string
        xdefault?: boolean | null
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        captionId: rest[0] as string,
        bucketId: rest[1] as string,
        fileId: rest[2] as string,
        name: rest[3] as string,
        code: rest[4] as string,
        xdefault: rest[5] as boolean | null,
      }
    }

    const videoId = params.videoId
    const captionId = params.captionId
    const bucketId = params.bucketId
    const fileId = params.fileId
    const name = params.name
    const code = params.code
    const xdefault = params.xdefault

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof captionId === 'undefined' || captionId === '') {
      throw new AppwriteException('Missing required parameter: "captionId"')
    }
    const apiPath = '/videos/{videoId}/captions/{captionId}'
      .replace('{videoId}', encodePath(videoId))
      .replace('{captionId}', encodePath(captionId))
    const apiPayload: Payload = {}
    if (typeof bucketId !== 'undefined') {
      apiPayload['bucketId'] = bucketId
    }
    if (typeof fileId !== 'undefined') {
      apiPayload['fileId'] = fileId
    }
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof code !== 'undefined') {
      apiPayload['code'] = code
    }
    if (typeof xdefault !== 'undefined') {
      apiPayload['default'] = xdefault
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('patch', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoCaption',
      models: {
        videoCaption: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            bucketId: '',
            fileId: '',
            name: '',
            code: '',
            default: false,
            embedded: false,
            targetDuration: '',
            status: '',
            error: '',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Delete a caption track by its unique ID, along with its packaged segments.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.captionId - Caption unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteCaption(params: { videoId: string; captionId: string }): Promise<{}>
  /**
   * Delete a caption track by its unique ID, along with its packaged segments.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} captionId - Caption unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  deleteCaption(videoId: string, captionId: string): Promise<{}>
  deleteCaption(
    paramsOrFirst: { videoId: string; captionId: string } | string,
    ...rest: [string?]
  ): Promise<{}> {
    let params: { videoId: string; captionId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        captionId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        captionId: rest[0] as string,
      }
    }

    const videoId = params.videoId
    const captionId = params.captionId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof captionId === 'undefined' || captionId === '') {
      throw new AppwriteException('Missing required parameter: "captionId"')
    }
    const apiPath = '/videos/{videoId}/captions/{captionId}'
      .replace('{videoId}', encodePath(videoId))
      .replace('{captionId}', encodePath(captionId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('delete', uri, apiHeaders, apiPayload)
  }

  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getCmafHlsManifest(params: { videoId: string }): string
  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getCmafHlsManifest(videoId: string): string
  getCmafHlsManifest(paramsOrFirst: { videoId: string } | string): string {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/outputs/cmaf/master.m3u8'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getCmafDashManifest(params: { videoId: string }): string
  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getCmafDashManifest(videoId: string): string
  getCmafDashManifest(paramsOrFirst: { videoId: string } | string): string {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/outputs/cmaf/master.mpd'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the HLS (or CMAF-HLS) media playlist for a single stream of a rendition. Players reach this from the master playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * CMAF stream playlists live under `/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8` and include `#EXT-X-MAP` for the fMP4 initialisation segment.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.renditionId - Rendition unique ID.
   * @param {number} params.streamId - Stream index within the rendition.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getCmafStreamManifest(params: {
    videoId: string
    renditionId: string
    streamId: number
  }): string
  /**
   * Get the HLS (or CMAF-HLS) media playlist for a single stream of a rendition. Players reach this from the master playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * CMAF stream playlists live under `/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8` and include `#EXT-X-MAP` for the fMP4 initialisation segment.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} renditionId - Rendition unique ID.
   * @param {number} streamId - Stream index within the rendition.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getCmafStreamManifest(
    videoId: string,
    renditionId: string,
    streamId: number,
  ): string
  getCmafStreamManifest(
    paramsOrFirst:
      | { videoId: string; renditionId: string; streamId: number }
      | string,
    ...rest: [string?, number?]
  ): string {
    let params: { videoId: string; renditionId: string; streamId: number }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        renditionId: string
        streamId: number
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        renditionId: rest[0] as string,
        streamId: rest[1] as number,
      }
    }

    const videoId = params.videoId
    const renditionId = params.renditionId
    const streamId = params.streamId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof renditionId === 'undefined' || renditionId === '') {
      throw new AppwriteException('Missing required parameter: "renditionId"')
    }
    if (typeof streamId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "streamId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/cmaf/renditions/{renditionId}/streams/{streamId}/playlist.m3u8'
        .replace('{videoId}', encodePath(videoId))
        .replace('{renditionId}', encodePath(renditionId))
        .replace('{streamId}', encodePath(streamId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getDashManifest(params: { videoId: string }): string
  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getDashManifest(videoId: string): string
  getDashManifest(paramsOrFirst: { videoId: string } | string): string {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/outputs/dash/master.mpd'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getHlsManifest(params: { videoId: string }): string
  /**
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   * For `output=cmaf`, both masters are available under `/outputs/cmaf/` and point at the same fMP4 segment set.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getHlsManifest(videoId: string): string
  getHlsManifest(paramsOrFirst: { videoId: string } | string): string {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/outputs/hls/master.m3u8'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the HLS (or CMAF-HLS) media playlist for a single stream of a rendition. Players reach this from the master playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * CMAF stream playlists live under `/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8` and include `#EXT-X-MAP` for the fMP4 initialisation segment.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.renditionId - Rendition unique ID.
   * @param {number} params.streamId - Stream index within the rendition.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getStreamManifest(params: {
    videoId: string
    renditionId: string
    streamId: number
  }): string
  /**
   * Get the HLS (or CMAF-HLS) media playlist for a single stream of a rendition. Players reach this from the master playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * CMAF stream playlists live under `/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8` and include `#EXT-X-MAP` for the fMP4 initialisation segment.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} renditionId - Rendition unique ID.
   * @param {number} streamId - Stream index within the rendition.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getStreamManifest(
    videoId: string,
    renditionId: string,
    streamId: number,
  ): string
  getStreamManifest(
    paramsOrFirst:
      | { videoId: string; renditionId: string; streamId: number }
      | string,
    ...rest: [string?, number?]
  ): string {
    let params: { videoId: string; renditionId: string; streamId: number }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        renditionId: string
        streamId: number
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        renditionId: rest[0] as string,
        streamId: rest[1] as number,
      }
    }

    const videoId = params.videoId
    const renditionId = params.renditionId
    const streamId = params.streamId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof renditionId === 'undefined' || renditionId === '') {
      throw new AppwriteException('Missing required parameter: "renditionId"')
    }
    if (typeof streamId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "streamId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/hls/renditions/{renditionId}/streams/{streamId}/playlist.m3u8'
        .replace('{videoId}', encodePath(videoId))
        .replace('{renditionId}', encodePath(renditionId))
        .replace('{streamId}', encodePath(streamId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the playable caption resource for a video output: an HLS captions playlist, or the WebVTT file itself for DASH.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Streaming output format.
   * @param {string} params.captionId - Caption unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getCaptionManifest(params: {
    videoId: string
    output: VideoOutput
    captionId: string
  }): string
  /**
   * Get the playable caption resource for a video output: an HLS captions playlist, or the WebVTT file itself for DASH.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Streaming output format.
   * @param {string} captionId - Caption unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getCaptionManifest(
    videoId: string,
    output: VideoOutput,
    captionId: string,
  ): string
  getCaptionManifest(
    paramsOrFirst:
      | { videoId: string; output: VideoOutput; captionId: string }
      | string,
    ...rest: [VideoOutput?, string?]
  ): string {
    let params: { videoId: string; output: VideoOutput; captionId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        output: VideoOutput
        captionId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        captionId: rest[1] as string,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const captionId = params.captionId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    if (typeof captionId === 'undefined' || captionId === '') {
      throw new AppwriteException('Missing required parameter: "captionId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/{output}/captions/{captionId}/manifest'
        .replace('{videoId}', encodePath(videoId))
        .replace('{output}', encodePath(output))
        .replace('{captionId}', encodePath(captionId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get a single WebVTT segment of a caption track. Players reach this from a captions playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Streaming output format.
   * @param {string} params.captionId - Caption unique ID.
   * @param {string} params.segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getCaptionSegment(params: {
    videoId: string
    output: VideoOutput
    captionId: string
    segmentId: string
  }): string
  /**
   * Get a single WebVTT segment of a caption track. Players reach this from a captions playlist; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Streaming output format.
   * @param {string} captionId - Caption unique ID.
   * @param {string} segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getCaptionSegment(
    videoId: string,
    output: VideoOutput,
    captionId: string,
    segmentId: string,
  ): string
  getCaptionSegment(
    paramsOrFirst:
      | {
          videoId: string
          output: VideoOutput
          captionId: string
          segmentId: string
        }
      | string,
    ...rest: [VideoOutput?, string?, string?]
  ): string {
    let params: {
      videoId: string
      output: VideoOutput
      captionId: string
      segmentId: string
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        output: VideoOutput
        captionId: string
        segmentId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        captionId: rest[1] as string,
        segmentId: rest[2] as string,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const captionId = params.captionId
    const segmentId = params.segmentId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    if (typeof captionId === 'undefined' || captionId === '') {
      throw new AppwriteException('Missing required parameter: "captionId"')
    }
    if (typeof segmentId === 'undefined' || segmentId === '') {
      throw new AppwriteException('Missing required parameter: "segmentId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/{output}/captions/{captionId}/segments/{segmentId}'
        .replace('{videoId}', encodePath(videoId))
        .replace('{output}', encodePath(output))
        .replace('{captionId}', encodePath(captionId))
        .replace('{segmentId}', encodePath(segmentId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get a single media segment of a rendition. Players reach this from a media playlist or MPD; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * HLS MPEG-TS segments use `video/mp2t`. DASH and CMAF fMP4 segments use `video/iso.segment`.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Streaming output format.
   * @param {string} params.renditionId - Rendition unique ID.
   * @param {string} params.segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getSegment(params: {
    videoId: string
    output: VideoOutput
    renditionId: string
    segmentId: string
  }): string
  /**
   * Get a single media segment of a rendition. Players reach this from a media playlist or MPD; it is not usually requested directly.
   *
   * Guests can follow these URLs when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   * HLS MPEG-TS segments use `video/mp2t`. DASH and CMAF fMP4 segments use `video/iso.segment`.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Streaming output format.
   * @param {string} renditionId - Rendition unique ID.
   * @param {string} segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getSegment(
    videoId: string,
    output: VideoOutput,
    renditionId: string,
    segmentId: string,
  ): string
  getSegment(
    paramsOrFirst:
      | {
          videoId: string
          output: VideoOutput
          renditionId: string
          segmentId: string
        }
      | string,
    ...rest: [VideoOutput?, string?, string?]
  ): string {
    let params: {
      videoId: string
      output: VideoOutput
      renditionId: string
      segmentId: string
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        output: VideoOutput
        renditionId: string
        segmentId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        renditionId: rest[1] as string,
        segmentId: rest[2] as string,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const renditionId = params.renditionId
    const segmentId = params.segmentId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    if (typeof renditionId === 'undefined' || renditionId === '') {
      throw new AppwriteException('Missing required parameter: "renditionId"')
    }
    if (typeof segmentId === 'undefined' || segmentId === '') {
      throw new AppwriteException('Missing required parameter: "segmentId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/{output}/renditions/{renditionId}/segments/{segmentId}'
        .replace('{videoId}', encodePath(videoId))
        .replace('{output}', encodePath(output))
        .replace('{renditionId}', encodePath(renditionId))
        .replace('{segmentId}', encodePath(segmentId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get a list of all the renditions of a video.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Only return renditions packaged for this output format.
   * @param {VideoRenditionStatus} params.status - Only return renditions in this transcoding state.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRenditionList>}
   */
  listRenditions(params: {
    videoId: string
    output?: VideoOutput
    status?: VideoRenditionStatus
  }): Promise<VideoRenditionList>
  /**
   * Get a list of all the renditions of a video.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Only return renditions packaged for this output format.
   * @param {VideoRenditionStatus} status - Only return renditions in this transcoding state.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRenditionList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  listRenditions(
    videoId: string,
    output?: VideoOutput,
    status?: VideoRenditionStatus,
  ): Promise<VideoRenditionList>
  listRenditions(
    paramsOrFirst:
      | {
          videoId: string
          output?: VideoOutput
          status?: VideoRenditionStatus
        }
      | string,
    ...rest: [VideoOutput?, VideoRenditionStatus?]
  ): Promise<VideoRenditionList> {
    let params: {
      videoId: string
      output?: VideoOutput
      status?: VideoRenditionStatus
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        output?: VideoOutput
        status?: VideoRenditionStatus
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        status: rest[1] as VideoRenditionStatus,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const status = params.status

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/renditions'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    if (typeof output !== 'undefined') {
      apiPayload['output'] = output
    }
    if (typeof status !== 'undefined') {
      apiPayload['status'] = status
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoRenditionList',
      models: {
        videoRenditionList: {
          defaults: {
            total: 0,
            renditions: [],
          },
          properties: {
            renditions: {
              items: 'videoRendition',
            },
          },
        },
        videoRendition: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            profileId: '',
            name: '',
            startedAt: '',
            endedAt: '',
            width: 0,
            height: 0,
            videoBitRate: 0,
            audioBitRate: 0,
            targetDuration: '',
            status: '',
            error: '',
            progress: '',
            output: '',
            codec: 'h264',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress. The worker downloads the source file for this job, probes metadata and extracts embedded captions when needed, then deletes the working copy when the job ends.
   *
   * The profile codec must be enabled on this instance. A disabled codec fails with `video_codec_disabled`.
   *
   * Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).
   *
   * A video accepts 10 rendition creates per hour. The next create returns `general_rate_limit_exceeded`.
   *
   * CMAF packs shared fMP4 segments once and exposes both HLS (`/outputs/cmaf/master.m3u8`) and DASH (`/outputs/cmaf/master.mpd`) masters for the same encode.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.profileId - Video profile unique ID to encode against.
   * @param {VideoOutput} params.output - Streaming output format to package as.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRendition>}
   */
  createRendition(params: {
    videoId: string
    profileId: string
    output: VideoOutput
  }): Promise<VideoRendition>
  /**
   * Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress. The worker downloads the source file for this job, probes metadata and extracts embedded captions when needed, then deletes the working copy when the job ends.
   *
   * The profile codec must be enabled on this instance. A disabled codec fails with `video_codec_disabled`.
   *
   * Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).
   *
   * A video accepts 10 rendition creates per hour. The next create returns `general_rate_limit_exceeded`.
   *
   * CMAF packs shared fMP4 segments once and exposes both HLS (`/outputs/cmaf/master.m3u8`) and DASH (`/outputs/cmaf/master.mpd`) masters for the same encode.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} profileId - Video profile unique ID to encode against.
   * @param {VideoOutput} output - Streaming output format to package as.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRendition>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createRendition(
    videoId: string,
    profileId: string,
    output: VideoOutput,
  ): Promise<VideoRendition>
  createRendition(
    paramsOrFirst:
      | { videoId: string; profileId: string; output: VideoOutput }
      | string,
    ...rest: [string?, VideoOutput?]
  ): Promise<VideoRendition> {
    let params: { videoId: string; profileId: string; output: VideoOutput }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        profileId: string
        output: VideoOutput
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        profileId: rest[0] as string,
        output: rest[1] as VideoOutput,
      }
    }

    const videoId = params.videoId
    const profileId = params.profileId
    const output = params.output

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof profileId === 'undefined') {
      throw new AppwriteException('Missing required parameter: "profileId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    const apiPath = '/videos/{videoId}/renditions'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    if (typeof profileId !== 'undefined') {
      apiPayload['profileId'] = profileId
    }
    if (typeof output !== 'undefined') {
      apiPayload['output'] = output
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('post', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoRendition',
      models: {
        videoRendition: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            profileId: '',
            name: '',
            startedAt: '',
            endedAt: '',
            width: 0,
            height: 0,
            videoBitRate: 0,
            audioBitRate: 0,
            targetDuration: '',
            status: '',
            error: '',
            progress: '',
            output: '',
            codec: 'h264',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Get a rendition by its unique ID, including its current transcoding status and progress.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.renditionId - Rendition unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRendition>}
   */
  getRendition(params: {
    videoId: string
    renditionId: string
  }): Promise<VideoRendition>
  /**
   * Get a rendition by its unique ID, including its current transcoding status and progress.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} renditionId - Rendition unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoRendition>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getRendition(videoId: string, renditionId: string): Promise<VideoRendition>
  getRendition(
    paramsOrFirst: { videoId: string; renditionId: string } | string,
    ...rest: [string?]
  ): Promise<VideoRendition> {
    let params: { videoId: string; renditionId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        renditionId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        renditionId: rest[0] as string,
      }
    }

    const videoId = params.videoId
    const renditionId = params.renditionId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof renditionId === 'undefined' || renditionId === '') {
      throw new AppwriteException('Missing required parameter: "renditionId"')
    }
    const apiPath = '/videos/{videoId}/renditions/{renditionId}'
      .replace('{videoId}', encodePath(videoId))
      .replace('{renditionId}', encodePath(renditionId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoRendition',
      models: {
        videoRendition: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            videoId: '',
            profileId: '',
            name: '',
            startedAt: '',
            endedAt: '',
            width: 0,
            height: 0,
            videoBitRate: 0,
            audioBitRate: 0,
            targetDuration: '',
            status: '',
            error: '',
            progress: '',
            output: '',
            codec: 'h264',
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Delete a rendition by its unique ID, along with its packaged segments and transcoded output. Fails with `video_rendition_in_progress` while the rendition is started or ended, unless it has not been updated within the rendition retention window.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.renditionId - Rendition unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteRendition(params: { videoId: string; renditionId: string }): Promise<{}>
  /**
   * Delete a rendition by its unique ID, along with its packaged segments and transcoded output. Fails with `video_rendition_in_progress` while the rendition is started or ended, unless it has not been updated within the rendition retention window.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} renditionId - Rendition unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  deleteRendition(videoId: string, renditionId: string): Promise<{}>
  deleteRendition(
    paramsOrFirst: { videoId: string; renditionId: string } | string,
    ...rest: [string?]
  ): Promise<{}> {
    let params: { videoId: string; renditionId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        renditionId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        renditionId: rest[0] as string,
      }
    }

    const videoId = params.videoId
    const renditionId = params.renditionId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof renditionId === 'undefined' || renditionId === '') {
      throw new AppwriteException('Missing required parameter: "renditionId"')
    }
    const apiPath = '/videos/{videoId}/renditions/{renditionId}'
      .replace('{videoId}', encodePath(videoId))
      .replace('{renditionId}', encodePath(renditionId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('delete', uri, apiHeaders, apiPayload)
  }

  /**
   * Get a single thumbnail sheet from a video's timeline as an image. You can crop the returned image and choose its output format.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.thumbnailId - Thumbnail unique ID.
   * @param {number} params.width - Resize thumbnail image width, in pixels.
   * @param {number} params.height - Resize thumbnail image height, in pixels.
   * @param {ImageFormat} params.output - Output format type (jpeg, jpg, png, gif and webp).
   * @throws {AppwriteException}
   * @returns {string}
   */
  getThumbnail(params: {
    videoId: string
    thumbnailId: string
    width?: number
    height?: number
    output?: ImageFormat
  }): string
  /**
   * Get a single thumbnail sheet from a video's timeline as an image. You can crop the returned image and choose its output format.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} thumbnailId - Thumbnail unique ID.
   * @param {number} width - Resize thumbnail image width, in pixels.
   * @param {number} height - Resize thumbnail image height, in pixels.
   * @param {ImageFormat} output - Output format type (jpeg, jpg, png, gif and webp).
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getThumbnail(
    videoId: string,
    thumbnailId: string,
    width?: number,
    height?: number,
    output?: ImageFormat,
  ): string
  getThumbnail(
    paramsOrFirst:
      | {
          videoId: string
          thumbnailId: string
          width?: number
          height?: number
          output?: ImageFormat
        }
      | string,
    ...rest: [string?, number?, number?, ImageFormat?]
  ): string {
    let params: {
      videoId: string
      thumbnailId: string
      width?: number
      height?: number
      output?: ImageFormat
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        thumbnailId: string
        width?: number
        height?: number
        output?: ImageFormat
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        thumbnailId: rest[0] as string,
        width: rest[1] as number,
        height: rest[2] as number,
        output: rest[3] as ImageFormat,
      }
    }

    const videoId = params.videoId
    const thumbnailId = params.thumbnailId
    const width = params.width
    const height = params.height
    const output = params.output

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof thumbnailId === 'undefined' || thumbnailId === '') {
      throw new AppwriteException('Missing required parameter: "thumbnailId"')
    }
    const apiPath = '/videos/{videoId}/thumbnails/{thumbnailId}'
      .replace('{videoId}', encodePath(videoId))
      .replace('{thumbnailId}', encodePath(thumbnailId))
    const apiPayload: Payload = {}
    if (typeof width !== 'undefined') {
      apiPayload['width'] = width
    }
    if (typeof height !== 'undefined') {
      apiPayload['height'] = height
    }
    if (typeof output !== 'undefined') {
      apiPayload['output'] = output
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    apiPayload['project'] = this.client.config.project
    apiPayload['impersonateuserid'] = this.client.config.impersonateuserid

    for (const [key, value] of Object.entries(Client.flatten(apiPayload))) {
      uri.searchParams.append(key, value)
    }

    return uri.toString()
  }

  /**
   * Get the WebVTT thumbnail timeline for a video, used to render scrubbing thumbnails in a player. Timeline generation is queued when the video is created. Subscribe to `videos.[videoId].timelines.update` for probe and terminal updates, or poll this endpoint until it returns a WebVTT document. Audio-only sources produce no timeline and this endpoint returns `video_timelines_not_found`.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<string>}
   */
  getTimelines(params: { videoId: string }): Promise<string>
  /**
   * Get the WebVTT thumbnail timeline for a video, used to render scrubbing thumbnails in a player. Timeline generation is queued when the video is created. Subscribe to `videos.[videoId].timelines.update` for probe and terminal updates, or poll this endpoint until it returns a WebVTT document. Audio-only sources produce no timeline and this endpoint returns `video_timelines_not_found`.
   *
   * Guests can request this URL when they hold `videos.play` (or `videos.read`) **and** the video document grants them read. A video with `read("any")` or `read("guests")` is playable without a session.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<string>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getTimelines(videoId: string): Promise<string>
  getTimelines(paramsOrFirst: { videoId: string } | string): Promise<string> {
    let params: { videoId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
      }
    }

    const videoId = params.videoId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    const apiPath = '/videos/{videoId}/timelines'.replace(
      '{videoId}',
      encodePath(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'text/plain',
    }

    return this.client.call('get', uri, apiHeaders, apiPayload, 'text')
  }

  /**
   * Get a list of video profiles in the current project (`GET /v1/project/videos/profiles`). Pass `codec` to browse one encode ladder at a time; defaults to `h264`. Search with `Query.search()` on the `search` attribute inside `queries`.
   *
   *
   * @param {string[]} params.queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: search
   * @param {string} params.codec - Only return profiles for this encode codec. Defaults to `h264`.
   * @param {boolean} params.total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfileList>}
   */
  listProfiles(params?: {
    queries?: string[]
    codec?: string
    total?: boolean
  }): Promise<VideoProfileList>
  /**
   * Get a list of video profiles in the current project (`GET /v1/project/videos/profiles`). Pass `codec` to browse one encode ladder at a time; defaults to `h264`. Search with `Query.search()` on the `search` attribute inside `queries`.
   *
   *
   * @param {string[]} queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: search
   * @param {string} codec - Only return profiles for this encode codec. Defaults to `h264`.
   * @param {boolean} total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfileList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  listProfiles(
    queries?: string[],
    codec?: string,
    total?: boolean,
  ): Promise<VideoProfileList>
  listProfiles(
    paramsOrFirst?:
      | { queries?: string[]; codec?: string; total?: boolean }
      | string[],
    ...rest: [string?, boolean?]
  ): Promise<VideoProfileList> {
    let params: { queries?: string[]; codec?: string; total?: boolean }

    if (
      (typeof paramsOrFirst === 'undefined' && rest.length === 0) ||
      (paramsOrFirst &&
        typeof paramsOrFirst === 'object' &&
        !Array.isArray(paramsOrFirst))
    ) {
      params = (paramsOrFirst || {}) as {
        queries?: string[]
        codec?: string
        total?: boolean
      }
    } else {
      params = {
        queries: paramsOrFirst as string[],
        codec: rest[0] as string,
        total: rest[1] as boolean,
      }
    }

    const queries = params.queries
    const codec = params.codec
    const total = params.total

    const apiPath = '/project/videos/profiles'
    const apiPayload: Payload = {}
    if (typeof queries !== 'undefined') {
      apiPayload['queries'] = queries
    }
    if (typeof codec !== 'undefined') {
      apiPayload['codec'] = codec
    }
    if (typeof total !== 'undefined') {
      apiPayload['total'] = total
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoProfileList',
      models: {
        videoProfileList: {
          defaults: {
            total: 0,
            profiles: [],
          },
          properties: {
            profiles: {
              items: 'videoProfile',
            },
          },
        },
        videoProfile: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            name: '',
            codec: 'h264',
            videoBitRate: 0,
            audioBitRate: 0,
            width: 0,
            height: 0,
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Create a video profile (`POST /v1/project/videos/profiles`) describing an encoding target: output dimensions, video/audio bitrates, and encode codec. Only codecs enabled on this instance are accepted; the default is `h264`. A disabled codec is rejected. Creating a profile with the same width, height, bitrates, and codec fails with `video_profile_already_exists`. Renditions are encoded against a profile.
   *
   *
   * @param {string} params.name - Video profile name.
   * @param {number} params.videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} params.audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} params.width - Target video width in pixels.
   * @param {number} params.height - Target video height in pixels.
   * @param {string} params.codec - Video encode codec. Only codecs enabled on this instance are accepted. Defaults to `h264`.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   */
  createProfile(params: {
    name: string
    videoBitRate: number
    audioBitRate: number
    width: number
    height: number
    codec?: string
  }): Promise<VideoProfile>
  /**
   * Create a video profile (`POST /v1/project/videos/profiles`) describing an encoding target: output dimensions, video/audio bitrates, and encode codec. Only codecs enabled on this instance are accepted; the default is `h264`. A disabled codec is rejected. Creating a profile with the same width, height, bitrates, and codec fails with `video_profile_already_exists`. Renditions are encoded against a profile.
   *
   *
   * @param {string} name - Video profile name.
   * @param {number} videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} width - Target video width in pixels.
   * @param {number} height - Target video height in pixels.
   * @param {string} codec - Video encode codec. Only codecs enabled on this instance are accepted. Defaults to `h264`.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createProfile(
    name: string,
    videoBitRate: number,
    audioBitRate: number,
    width: number,
    height: number,
    codec?: string,
  ): Promise<VideoProfile>
  createProfile(
    paramsOrFirst:
      | {
          name: string
          videoBitRate: number
          audioBitRate: number
          width: number
          height: number
          codec?: string
        }
      | string,
    ...rest: [number?, number?, number?, number?, string?]
  ): Promise<VideoProfile> {
    let params: {
      name: string
      videoBitRate: number
      audioBitRate: number
      width: number
      height: number
      codec?: string
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        name: string
        videoBitRate: number
        audioBitRate: number
        width: number
        height: number
        codec?: string
      }
    } else {
      params = {
        name: paramsOrFirst as string,
        videoBitRate: rest[0] as number,
        audioBitRate: rest[1] as number,
        width: rest[2] as number,
        height: rest[3] as number,
        codec: rest[4] as string,
      }
    }

    const name = params.name
    const videoBitRate = params.videoBitRate
    const audioBitRate = params.audioBitRate
    const width = params.width
    const height = params.height
    const codec = params.codec

    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    if (typeof videoBitRate === 'undefined') {
      throw new AppwriteException('Missing required parameter: "videoBitRate"')
    }
    if (typeof audioBitRate === 'undefined') {
      throw new AppwriteException('Missing required parameter: "audioBitRate"')
    }
    if (typeof width === 'undefined') {
      throw new AppwriteException('Missing required parameter: "width"')
    }
    if (typeof height === 'undefined') {
      throw new AppwriteException('Missing required parameter: "height"')
    }
    const apiPath = '/project/videos/profiles'
    const apiPayload: Payload = {}
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof videoBitRate !== 'undefined') {
      apiPayload['videoBitRate'] = videoBitRate
    }
    if (typeof audioBitRate !== 'undefined') {
      apiPayload['audioBitRate'] = audioBitRate
    }
    if (typeof width !== 'undefined') {
      apiPayload['width'] = width
    }
    if (typeof height !== 'undefined') {
      apiPayload['height'] = height
    }
    if (typeof codec !== 'undefined') {
      apiPayload['codec'] = codec
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('post', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoProfile',
      models: {
        videoProfile: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            name: '',
            codec: 'h264',
            videoBitRate: 0,
            audioBitRate: 0,
            width: 0,
            height: 0,
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Get a video profile by its unique ID (`GET /v1/project/videos/profiles/:profileId`).
   *
   *
   * @param {string} params.profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   */
  getProfile(params: { profileId: string }): Promise<VideoProfile>
  /**
   * Get a video profile by its unique ID (`GET /v1/project/videos/profiles/:profileId`).
   *
   *
   * @param {string} profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getProfile(profileId: string): Promise<VideoProfile>
  getProfile(
    paramsOrFirst: { profileId: string } | string,
  ): Promise<VideoProfile> {
    let params: { profileId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { profileId: string }
    } else {
      params = {
        profileId: paramsOrFirst as string,
      }
    }

    const profileId = params.profileId

    if (typeof profileId === 'undefined' || profileId === '') {
      throw new AppwriteException('Missing required parameter: "profileId"')
    }
    const apiPath = '/project/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodePath(profileId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoProfile',
      models: {
        videoProfile: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            name: '',
            codec: 'h264',
            videoBitRate: 0,
            audioBitRate: 0,
            width: 0,
            height: 0,
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Update a video profile by its unique ID (`PATCH /v1/project/videos/profiles/:profileId`). Existing renditions are not re-encoded. Changing the profile onto the same width, height, bitrates, and codec as another profile fails with `video_profile_already_exists`.
   *
   *
   * @param {string} params.profileId - Video profile unique ID.
   * @param {string} params.name - Video profile name.
   * @param {number} params.videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} params.audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} params.width - Target video width in pixels.
   * @param {number} params.height - Target video height in pixels.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   */
  updateProfile(params: {
    profileId: string
    name: string
    videoBitRate: number
    audioBitRate: number
    width: number
    height: number
  }): Promise<VideoProfile>
  /**
   * Update a video profile by its unique ID (`PATCH /v1/project/videos/profiles/:profileId`). Existing renditions are not re-encoded. Changing the profile onto the same width, height, bitrates, and codec as another profile fails with `video_profile_already_exists`.
   *
   *
   * @param {string} profileId - Video profile unique ID.
   * @param {string} name - Video profile name.
   * @param {number} videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} width - Target video width in pixels.
   * @param {number} height - Target video height in pixels.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  updateProfile(
    profileId: string,
    name: string,
    videoBitRate: number,
    audioBitRate: number,
    width: number,
    height: number,
  ): Promise<VideoProfile>
  updateProfile(
    paramsOrFirst:
      | {
          profileId: string
          name: string
          videoBitRate: number
          audioBitRate: number
          width: number
          height: number
        }
      | string,
    ...rest: [string?, number?, number?, number?, number?]
  ): Promise<VideoProfile> {
    let params: {
      profileId: string
      name: string
      videoBitRate: number
      audioBitRate: number
      width: number
      height: number
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        profileId: string
        name: string
        videoBitRate: number
        audioBitRate: number
        width: number
        height: number
      }
    } else {
      params = {
        profileId: paramsOrFirst as string,
        name: rest[0] as string,
        videoBitRate: rest[1] as number,
        audioBitRate: rest[2] as number,
        width: rest[3] as number,
        height: rest[4] as number,
      }
    }

    const profileId = params.profileId
    const name = params.name
    const videoBitRate = params.videoBitRate
    const audioBitRate = params.audioBitRate
    const width = params.width
    const height = params.height

    if (typeof profileId === 'undefined' || profileId === '') {
      throw new AppwriteException('Missing required parameter: "profileId"')
    }
    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    if (typeof videoBitRate === 'undefined') {
      throw new AppwriteException('Missing required parameter: "videoBitRate"')
    }
    if (typeof audioBitRate === 'undefined') {
      throw new AppwriteException('Missing required parameter: "audioBitRate"')
    }
    if (typeof width === 'undefined') {
      throw new AppwriteException('Missing required parameter: "width"')
    }
    if (typeof height === 'undefined') {
      throw new AppwriteException('Missing required parameter: "height"')
    }
    const apiPath = '/project/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodePath(profileId),
    )
    const apiPayload: Payload = {}
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    if (typeof videoBitRate !== 'undefined') {
      apiPayload['videoBitRate'] = videoBitRate
    }
    if (typeof audioBitRate !== 'undefined') {
      apiPayload['audioBitRate'] = audioBitRate
    }
    if (typeof width !== 'undefined') {
      apiPayload['width'] = width
    }
    if (typeof height !== 'undefined') {
      apiPayload['height'] = height
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    const request = this.client.call('patch', uri, apiHeaders, apiPayload)
    const defaults = {
      root: 'videoProfile',
      models: {
        videoProfile: {
          defaults: {
            $id: '',
            $createdAt: '',
            $updatedAt: '',
            name: '',
            codec: 'h264',
            videoBitRate: 0,
            audioBitRate: 0,
            width: 0,
            height: 0,
          },
        },
      },
    }

    return request.then((response) => applyDefaults(response, defaults))
  }

  /**
   * Delete a video profile by its unique ID (`DELETE /v1/project/videos/profiles/:profileId`). Renditions already encoded against it are left in place.
   *
   *
   * @param {string} params.profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteProfile(params: { profileId: string }): Promise<{}>
  /**
   * Delete a video profile by its unique ID (`DELETE /v1/project/videos/profiles/:profileId`). Renditions already encoded against it are left in place.
   *
   *
   * @param {string} profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  deleteProfile(profileId: string): Promise<{}>
  deleteProfile(paramsOrFirst: { profileId: string } | string): Promise<{}> {
    let params: { profileId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { profileId: string }
    } else {
      params = {
        profileId: paramsOrFirst as string,
      }
    }

    const profileId = params.profileId

    if (typeof profileId === 'undefined' || profileId === '') {
      throw new AppwriteException('Missing required parameter: "profileId"')
    }
    const apiPath = '/project/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodePath(profileId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('delete', uri, apiHeaders, apiPayload)
  }
}

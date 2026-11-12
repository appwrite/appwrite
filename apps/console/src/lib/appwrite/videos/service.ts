/**
 * Hand-written Videos client: the videos API has not shipped in a Console SDK
 * release yet, so the pinned SDK carries no `Videos` service. Copied from the code the
 * SDK generator produces for the `/videos` API; delete this folder for the generated one once it ships.
 */

import { AppwriteException, Client, type Payload } from '@appwrite.io/console'
import type {
  Video,
  VideoList,
  VideoProfile,
  VideoProfileList,
  VideoRendition,
  VideoRenditionList,
  VideoSubtitle,
  VideoSubtitleList,
} from './models'
import { VideoOutput, VideoRenditionStatus } from './enums'
import { ImageFormat } from '@appwrite.io/console'

export class Videos {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /**
   * Get a list of all the videos in the current project.
   *
   *
   * @param {string[]} params.queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: bucketId, fileId, name, size, status, format, duration, width, height, videoCodec, videoBitRate, audioCodec, audioBitRate
   * @param {string} params.search - Search term to filter your list results. Max length: 256 chars.
   * @param {boolean} params.total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoList>}
   */
  list(params?: {
    queries?: string[]
    search?: string
    total?: boolean
  }): Promise<VideoList>
  /**
   * Get a list of all the videos in the current project.
   *
   *
   * @param {string[]} queries - Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. You may filter on the following attributes: bucketId, fileId, name, size, status, format, duration, width, height, videoCodec, videoBitRate, audioCodec, audioBitRate
   * @param {string} search - Search term to filter your list results. Max length: 256 chars.
   * @param {boolean} total - When set to false, the total count returned will be 0 and will not be calculated.
   * @throws {AppwriteException}
   * @returns {Promise<VideoList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  list(queries?: string[], search?: string, total?: boolean): Promise<VideoList>
  list(
    paramsOrFirst?:
      | { queries?: string[]; search?: string; total?: boolean }
      | string[],
    ...rest: [string?, boolean?]
  ): Promise<VideoList> {
    let params: { queries?: string[]; search?: string; total?: boolean }

    if (
      (typeof paramsOrFirst === 'undefined' && rest.length === 0) ||
      (paramsOrFirst &&
        typeof paramsOrFirst === 'object' &&
        !Array.isArray(paramsOrFirst))
    ) {
      params = (paramsOrFirst || {}) as {
        queries?: string[]
        search?: string
        total?: boolean
      }
    } else {
      params = {
        queries: paramsOrFirst as string[],
        search: rest[0] as string,
        total: rest[1] as boolean,
      }
    }

    const queries = params.queries
    const search = params.search
    const total = params.total

    const apiPath = '/videos'
    const apiPayload: Payload = {}
    if (typeof queries !== 'undefined') {
      apiPayload['queries'] = queries
    }
    if (typeof search !== 'undefined') {
      apiPayload['search'] = search
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

    return request
  }

  /**
   * Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file. Creating a video only stores the document in `pending` status; it does not start a download. Call the create-source endpoint next, then poll until `status` is `ready` before creating a timeline or rendition.
   *
   * An optional `name` defaults to the source file name. Uploaded subtitle files override auto-extracted tracks for the same language once extraction has run as part of create-source.
   *
   *
   * @param {string} params.bucketId - Storage bucket unique ID holding the source file.
   * @param {string} params.fileId - Source file unique ID.
   * @param {string} params.name - Video name. Defaults to the source file name. Max length: 128 chars.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  create(params: {
    bucketId: string
    fileId: string
    name?: string
  }): Promise<Video>
  /**
   * Create a video resource from an existing file in a storage bucket. The source file must be a video or audio file. Creating a video only stores the document in `pending` status; it does not start a download. Call the create-source endpoint next, then poll until `status` is `ready` before creating a timeline or rendition.
   *
   * An optional `name` defaults to the source file name. Uploaded subtitle files override auto-extracted tracks for the same language once extraction has run as part of create-source.
   *
   *
   * @param {string} bucketId - Storage bucket unique ID holding the source file.
   * @param {string} fileId - Source file unique ID.
   * @param {string} name - Video name. Defaults to the source file name. Max length: 128 chars.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  create(bucketId: string, fileId: string, name?: string): Promise<Video>
  create(
    paramsOrFirst: { bucketId: string; fileId: string; name?: string } | string,
    ...rest: [string?, string?]
  ): Promise<Video> {
    let params: { bucketId: string; fileId: string; name?: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        bucketId: string
        fileId: string
        name?: string
      }
    } else {
      params = {
        bucketId: paramsOrFirst as string,
        fileId: rest[0] as string,
        name: rest[1] as string,
      }
    }

    const bucketId = params.bucketId
    const fileId = params.fileId
    const name = params.name

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
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('post', uri, apiHeaders, apiPayload)
  }

  /**
   * Get a list of all the video profiles in the current project.
   *
   *
   * @param {string} params.search - Search term to filter your list results. Max length: 256 chars.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfileList>}
   */
  listProfiles(params?: { search?: string }): Promise<VideoProfileList>
  /**
   * Get a list of all the video profiles in the current project.
   *
   *
   * @param {string} search - Search term to filter your list results. Max length: 256 chars.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfileList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  listProfiles(search?: string): Promise<VideoProfileList>
  listProfiles(
    paramsOrFirst?: { search?: string } | string,
  ): Promise<VideoProfileList> {
    let params: { search?: string }

    if (
      typeof paramsOrFirst === 'undefined' ||
      (paramsOrFirst &&
        typeof paramsOrFirst === 'object' &&
        !Array.isArray(paramsOrFirst))
    ) {
      params = (paramsOrFirst || {}) as { search?: string }
    } else {
      params = {
        search: paramsOrFirst as string,
      }
    }

    const search = params.search

    const apiPath = '/videos/profiles'
    const apiPayload: Payload = {}
    if (typeof search !== 'undefined') {
      apiPayload['search'] = search
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)

    return request
  }

  /**
   * Create a video profile describing an encoding target: output dimensions and video/audio bitrates. Renditions are encoded against a profile.
   *
   *
   * @param {string} params.name - Video profile name.
   * @param {number} params.videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} params.audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} params.width - Target video width in pixels.
   * @param {number} params.height - Target video height in pixels.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   */
  createProfile(params: {
    name: string
    videoBitRate: number
    audioBitRate: number
    width: number
    height: number
  }): Promise<VideoProfile>
  /**
   * Create a video profile describing an encoding target: output dimensions and video/audio bitrates. Renditions are encoded against a profile.
   *
   *
   * @param {string} name - Video profile name.
   * @param {number} videoBitRate - Target video bitrate in kilobits per second.
   * @param {number} audioBitRate - Target audio bitrate in kilobits per second.
   * @param {number} width - Target video width in pixels.
   * @param {number} height - Target video height in pixels.
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
  ): Promise<VideoProfile>
  createProfile(
    paramsOrFirst:
      | {
          name: string
          videoBitRate: number
          audioBitRate: number
          width: number
          height: number
        }
      | string,
    ...rest: [number?, number?, number?, number?]
  ): Promise<VideoProfile> {
    let params: {
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
        name: string
        videoBitRate: number
        audioBitRate: number
        width: number
        height: number
      }
    } else {
      params = {
        name: paramsOrFirst as string,
        videoBitRate: rest[0] as number,
        audioBitRate: rest[1] as number,
        width: rest[2] as number,
        height: rest[3] as number,
      }
    }

    const name = params.name
    const videoBitRate = params.videoBitRate
    const audioBitRate = params.audioBitRate
    const width = params.width
    const height = params.height

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
    const apiPath = '/videos/profiles'
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

    return this.client.call('post', uri, apiHeaders, apiPayload)
  }

  /**
   * Get a video profile by its unique ID.
   *
   *
   * @param {string} params.profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoProfile>}
   */
  getProfile(params: { profileId: string }): Promise<VideoProfile>
  /**
   * Get a video profile by its unique ID.
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
    const apiPath = '/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodeURIComponent(profileId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    return this.client.call('get', uri, apiHeaders, apiPayload)
  }

  /**
   * Update a video profile by its unique ID. Existing renditions are not re-encoded.
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
   * Update a video profile by its unique ID. Existing renditions are not re-encoded.
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
    const apiPath = '/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodeURIComponent(profileId),
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

    return this.client.call('patch', uri, apiHeaders, apiPayload)
  }

  /**
   * Delete a video profile by its unique ID. Renditions already encoded against it are left in place.
   *
   *
   * @param {string} params.profileId - Video profile unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteProfile(params: { profileId: string }): Promise<{}>
  /**
   * Delete a video profile by its unique ID. Renditions already encoded against it are left in place.
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
    const apiPath = '/videos/profiles/{profileId}'.replace(
      '{profileId}',
      encodeURIComponent(profileId),
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
      encodeURIComponent(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    return this.client.call('get', uri, apiHeaders, apiPayload)
  }

  /**
   * Update a video's name. The source file cannot be replaced; delete the video and create a new one to point at a different file.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.name - Video name. Max length: 128 chars.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  update(params: { videoId: string; name: string }): Promise<Video>
  /**
   * Update a video's name. The source file cannot be replaced; delete the video and create a new one to point at a different file.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} name - Video name. Max length: 128 chars.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  update(videoId: string, name: string): Promise<Video>
  update(
    paramsOrFirst: { videoId: string; name: string } | string,
    ...rest: [string?]
  ): Promise<Video> {
    let params: { videoId: string; name: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as { videoId: string; name: string }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        name: rest[0] as string,
      }
    }

    const videoId = params.videoId
    const name = params.name

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof name === 'undefined') {
      throw new AppwriteException('Missing required parameter: "name"')
    }
    const apiPath = '/videos/{videoId}'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
    )
    const apiPayload: Payload = {}
    if (typeof name !== 'undefined') {
      apiPayload['name'] = name
    }
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('put', uri, apiHeaders, apiPayload)
  }

  /**
   * Delete a video by its unique ID. This also removes every rendition, subtitle, preview and transcoded artifact derived from it.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  delete(params: { videoId: string }): Promise<{}>
  /**
   * Delete a video by its unique ID. This also removes every rendition, subtitle, preview and transcoded artifact derived from it.
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
      encodeURIComponent(videoId),
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
   * Get the top-level streaming manifest for a video output: an HLS master playlist (`master.m3u8`) or a DASH MPD (`master.mpd`). Hand this URL to a player to begin adaptive playback.
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
      encodeURIComponent(videoId),
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
      encodeURIComponent(videoId),
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
        .replace('{videoId}', encodeURIComponent(videoId))
        .replace('{renditionId}', encodeURIComponent(renditionId))
        .replace('{streamId}', encodeURIComponent(streamId))
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
      encodeURIComponent(videoId),
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
      encodeURIComponent(videoId),
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
        .replace('{videoId}', encodeURIComponent(videoId))
        .replace('{renditionId}', encodeURIComponent(renditionId))
        .replace('{streamId}', encodeURIComponent(streamId))
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
        .replace('{videoId}', encodeURIComponent(videoId))
        .replace('{output}', encodeURIComponent(output))
        .replace('{renditionId}', encodeURIComponent(renditionId))
        .replace('{segmentId}', encodeURIComponent(segmentId))
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
   * Get the playable subtitle resource for a video output: an HLS subtitle playlist, or the WebVTT file itself for DASH.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Streaming output format.
   * @param {string} params.subtitleId - Subtitle unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getSubtitleManifest(params: {
    videoId: string
    output: VideoOutput
    subtitleId: string
  }): string
  /**
   * Get the playable subtitle resource for a video output: an HLS subtitle playlist, or the WebVTT file itself for DASH.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Streaming output format.
   * @param {string} subtitleId - Subtitle unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getSubtitleManifest(
    videoId: string,
    output: VideoOutput,
    subtitleId: string,
  ): string
  getSubtitleManifest(
    paramsOrFirst:
      | { videoId: string; output: VideoOutput; subtitleId: string }
      | string,
    ...rest: [VideoOutput?, string?]
  ): string {
    let params: {
      videoId: string
      output: VideoOutput
      subtitleId: string
    }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        output: VideoOutput
        subtitleId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        subtitleId: rest[1] as string,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const subtitleId = params.subtitleId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    if (typeof subtitleId === 'undefined' || subtitleId === '') {
      throw new AppwriteException('Missing required parameter: "subtitleId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/{output}/subtitles/{subtitleId}/manifest'
        .replace('{videoId}', encodeURIComponent(videoId))
        .replace('{output}', encodeURIComponent(output))
        .replace('{subtitleId}', encodeURIComponent(subtitleId))
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
   * Get a single WebVTT segment of a subtitle track. Players reach this from a subtitle playlist; it is not usually requested directly.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {VideoOutput} params.output - Streaming output format.
   * @param {string} params.subtitleId - Subtitle unique ID.
   * @param {string} params.segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   */
  getSubtitleSegment(params: {
    videoId: string
    output: VideoOutput
    subtitleId: string
    segmentId: string
  }): string
  /**
   * Get a single WebVTT segment of a subtitle track. Players reach this from a subtitle playlist; it is not usually requested directly.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {VideoOutput} output - Streaming output format.
   * @param {string} subtitleId - Subtitle unique ID.
   * @param {string} segmentId - Segment unique ID.
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getSubtitleSegment(
    videoId: string,
    output: VideoOutput,
    subtitleId: string,
    segmentId: string,
  ): string
  getSubtitleSegment(
    paramsOrFirst:
      | {
          videoId: string
          output: VideoOutput
          subtitleId: string
          segmentId: string
        }
      | string,
    ...rest: [VideoOutput?, string?, string?]
  ): string {
    let params: {
      videoId: string
      output: VideoOutput
      subtitleId: string
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
        subtitleId: string
        segmentId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        output: rest[0] as VideoOutput,
        subtitleId: rest[1] as string,
        segmentId: rest[2] as string,
      }
    }

    const videoId = params.videoId
    const output = params.output
    const subtitleId = params.subtitleId
    const segmentId = params.segmentId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof output === 'undefined') {
      throw new AppwriteException('Missing required parameter: "output"')
    }
    if (typeof subtitleId === 'undefined' || subtitleId === '') {
      throw new AppwriteException('Missing required parameter: "subtitleId"')
    }
    if (typeof segmentId === 'undefined' || segmentId === '') {
      throw new AppwriteException('Missing required parameter: "segmentId"')
    }
    const apiPath =
      '/videos/{videoId}/outputs/{output}/subtitles/{subtitleId}/segments/{segmentId}'
        .replace('{videoId}', encodeURIComponent(videoId))
        .replace('{output}', encodeURIComponent(output))
        .replace('{subtitleId}', encodeURIComponent(subtitleId))
        .replace('{segmentId}', encodeURIComponent(segmentId))
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
   * Get a single sprite sheet from a video's timeline as an image. You can crop the returned image and choose its output format.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.previewId - Preview unique ID.
   * @param {number} params.width - Resize preview image width, in pixels.
   * @param {number} params.height - Resize preview image height, in pixels.
   * @param {ImageFormat} params.output - Output format type (jpeg, jpg, png, gif and webp).
   * @throws {AppwriteException}
   * @returns {string}
   */
  getPreview(params: {
    videoId: string
    previewId: string
    width?: number
    height?: number
    output?: ImageFormat
  }): string
  /**
   * Get a single sprite sheet from a video's timeline as an image. You can crop the returned image and choose its output format.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} previewId - Preview unique ID.
   * @param {number} width - Resize preview image width, in pixels.
   * @param {number} height - Resize preview image height, in pixels.
   * @param {ImageFormat} output - Output format type (jpeg, jpg, png, gif and webp).
   * @throws {AppwriteException}
   * @returns {string}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getPreview(
    videoId: string,
    previewId: string,
    width?: number,
    height?: number,
    output?: ImageFormat,
  ): string
  getPreview(
    paramsOrFirst:
      | {
          videoId: string
          previewId: string
          width?: number
          height?: number
          output?: ImageFormat
        }
      | string,
    ...rest: [string?, number?, number?, ImageFormat?]
  ): string {
    let params: {
      videoId: string
      previewId: string
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
        previewId: string
        width?: number
        height?: number
        output?: ImageFormat
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        previewId: rest[0] as string,
        width: rest[1] as number,
        height: rest[2] as number,
        output: rest[3] as ImageFormat,
      }
    }

    const videoId = params.videoId
    const previewId = params.previewId
    const width = params.width
    const height = params.height
    const output = params.output

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof previewId === 'undefined' || previewId === '') {
      throw new AppwriteException('Missing required parameter: "previewId"')
    }
    const apiPath = '/videos/{videoId}/previews/{previewId}'
      .replace('{videoId}', encodeURIComponent(videoId))
      .replace('{previewId}', encodeURIComponent(previewId))
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
      encodeURIComponent(videoId),
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

    return request
  }

  /**
   * Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress.
   *
   * The working copy must already be `ready`. Call the create-source endpoint first and wait until the video status is `ready`. If the working copy has been released (`removed`), this request fails with `video_source_removed`. Until the source is ready, this request fails with `video_not_ready`.
   *
   * Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).
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
   * Request a new rendition of a video, encoded against a video profile and packaged for HLS, DASH, or CMAF. The rendition is created immediately with a `pending` status and transcoded in the background; poll it or subscribe to realtime events to follow its progress.
   *
   * The working copy must already be `ready`. Call the create-source endpoint first and wait until the video status is `ready`. If the working copy has been released (`removed`), this request fails with `video_source_removed`. Until the source is ready, this request fails with `video_not_ready`.
   *
   * Each video may have only one rendition per profile and output combination. Creating a duplicate fails with `video_rendition_already_exists`. After an encode fails or is aborted, delete the rendition and create it again. The same profile may still be encoded for different outputs (for example HLS and DASH).
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
      encodeURIComponent(videoId),
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

    return this.client.call('post', uri, apiHeaders, apiPayload)
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
      .replace('{videoId}', encodeURIComponent(videoId))
      .replace('{renditionId}', encodeURIComponent(renditionId))
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    return this.client.call('get', uri, apiHeaders, apiPayload)
  }

  /**
   * Delete a rendition by its unique ID, along with its packaged segments and transcoded output.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.renditionId - Rendition unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteRendition(params: { videoId: string; renditionId: string }): Promise<{}>
  /**
   * Delete a rendition by its unique ID, along with its packaged segments and transcoded output.
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
      .replace('{videoId}', encodeURIComponent(videoId))
      .replace('{renditionId}', encodeURIComponent(renditionId))
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
   * Materialise the working copy of a video's source file onto the transcode volume. The video is created in `pending` status; this endpoint enqueues the download, probe, and embedded-subtitle extraction. Poll the video until `status` is `ready` before creating a timeline or rendition.
   *
   * If the working copy is already downloading the request fails with a 409 `video_source_in_progress` error, and if it is already ready with a 409 `video_source_already_exists` error. After the last in-flight rendition finishes the working copy is released and `status` becomes `removed`; call this endpoint again before creating further timelines or renditions.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  createSource(params: { videoId: string }): Promise<Video>
  /**
   * Materialise the working copy of a video's source file onto the transcode volume. The video is created in `pending` status; this endpoint enqueues the download, probe, and embedded-subtitle extraction. Poll the video until `status` is `ready` before creating a timeline or rendition.
   *
   * If the working copy is already downloading the request fails with a 409 `video_source_in_progress` error, and if it is already ready with a 409 `video_source_already_exists` error. After the last in-flight rendition finishes the working copy is released and `status` becomes `removed`; call this endpoint again before creating further timelines or renditions.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createSource(videoId: string): Promise<Video>
  createSource(paramsOrFirst: { videoId: string } | string): Promise<Video> {
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
    const apiPath = '/videos/{videoId}/source'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('post', uri, apiHeaders, apiPayload)
  }

  /**
   * Get a list of all the subtitle tracks attached to a video.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitleList>}
   */
  listSubtitles(params: { videoId: string }): Promise<VideoSubtitleList>
  /**
   * Get a list of all the subtitle tracks attached to a video.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitleList>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  listSubtitles(videoId: string): Promise<VideoSubtitleList>
  listSubtitles(
    paramsOrFirst: { videoId: string } | string,
  ): Promise<VideoSubtitleList> {
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
    const apiPath = '/videos/{videoId}/subtitles'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }

    const request = this.client.call('get', uri, apiHeaders, apiPayload)

    return request
  }

  /**
   * Add a subtitle track to a video from a WebVTT or SubRip file in a storage bucket. The file is normalised to WebVTT and segmented in the background.
   *
   * Uploaded tracks are listed alongside auto-extracted embedded tracks (`embedded: true`). When the current default is an auto-extracted track for the same language code, the upload takes the default flag so players pick the authored file first. Auto-extracted tracks are never removed automatically — delete a track explicitly to remove it. Extraction runs once per video, so a deleted extracted track is not re-created.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.bucketId - Storage bucket unique ID holding the subtitle file.
   * @param {string} params.fileId - Subtitle file unique ID.
   * @param {string} params.name - Subtitle display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} params.code - Subtitle ISO 639-2 three-letter language code.
   * @param {boolean} params.xdefault - Make this the default subtitle track for the video.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitle>}
   */
  createSubtitle(params: {
    videoId: string
    bucketId: string
    fileId: string
    name: string
    code: string
    xdefault?: boolean
  }): Promise<VideoSubtitle>
  /**
   * Add a subtitle track to a video from a WebVTT or SubRip file in a storage bucket. The file is normalised to WebVTT and segmented in the background.
   *
   * Uploaded tracks are listed alongside auto-extracted embedded tracks (`embedded: true`). When the current default is an auto-extracted track for the same language code, the upload takes the default flag so players pick the authored file first. Auto-extracted tracks are never removed automatically — delete a track explicitly to remove it. Extraction runs once per video, so a deleted extracted track is not re-created.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} bucketId - Storage bucket unique ID holding the subtitle file.
   * @param {string} fileId - Subtitle file unique ID.
   * @param {string} name - Subtitle display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} code - Subtitle ISO 639-2 three-letter language code.
   * @param {boolean} xdefault - Make this the default subtitle track for the video.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitle>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createSubtitle(
    videoId: string,
    bucketId: string,
    fileId: string,
    name: string,
    code: string,
    xdefault?: boolean,
  ): Promise<VideoSubtitle>
  createSubtitle(
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
  ): Promise<VideoSubtitle> {
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
    const apiPath = '/videos/{videoId}/subtitles'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
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

    return this.client.call('post', uri, apiHeaders, apiPayload)
  }

  /**
   * Update a subtitle track by its unique ID. Pass only `name`, `code`, and/or `default` to retag an extracted track (for example `und` → `heb` for Hebrew) without replacing the file. Changing `bucketId`/`fileId` re-packages the track in the background.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.subtitleId - Subtitle unique ID.
   * @param {string} params.bucketId - Storage bucket unique ID holding the subtitle file. Omit together with fileId to only update name, code, or default.
   * @param {string} params.fileId - Subtitle file unique ID. Omit together with bucketId to only update name, code, or default.
   * @param {string} params.name - Subtitle display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} params.code - Subtitle ISO 639-2 three-letter language code (for example `heb` for Hebrew).
   * @param {boolean | null} params.xdefault - Make this the default subtitle track for the video. Omit to leave unchanged.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitle>}
   */
  updateSubtitle(params: {
    videoId: string
    subtitleId: string
    bucketId?: string
    fileId?: string
    name?: string
    code?: string
    xdefault?: boolean | null
  }): Promise<VideoSubtitle>
  /**
   * Update a subtitle track by its unique ID. Pass only `name`, `code`, and/or `default` to retag an extracted track (for example `und` → `heb` for Hebrew) without replacing the file. Changing `bucketId`/`fileId` re-packages the track in the background.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} subtitleId - Subtitle unique ID.
   * @param {string} bucketId - Storage bucket unique ID holding the subtitle file. Omit together with fileId to only update name, code, or default.
   * @param {string} fileId - Subtitle file unique ID. Omit together with bucketId to only update name, code, or default.
   * @param {string} name - Subtitle display name. Allowed characters: a-z, A-Z, 0-9, space, and - . , ( ) _ '
   * @param {string} code - Subtitle ISO 639-2 three-letter language code (for example `heb` for Hebrew).
   * @param {boolean | null} xdefault - Make this the default subtitle track for the video. Omit to leave unchanged.
   * @throws {AppwriteException}
   * @returns {Promise<VideoSubtitle>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  updateSubtitle(
    videoId: string,
    subtitleId: string,
    bucketId?: string,
    fileId?: string,
    name?: string,
    code?: string,
    xdefault?: boolean | null,
  ): Promise<VideoSubtitle>
  updateSubtitle(
    paramsOrFirst:
      | {
          videoId: string
          subtitleId: string
          bucketId?: string
          fileId?: string
          name?: string
          code?: string
          xdefault?: boolean | null
        }
      | string,
    ...rest: [string?, string?, string?, string?, string?, (boolean | null)?]
  ): Promise<VideoSubtitle> {
    let params: {
      videoId: string
      subtitleId: string
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
        subtitleId: string
        bucketId?: string
        fileId?: string
        name?: string
        code?: string
        xdefault?: boolean | null
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        subtitleId: rest[0] as string,
        bucketId: rest[1] as string,
        fileId: rest[2] as string,
        name: rest[3] as string,
        code: rest[4] as string,
        xdefault: rest[5] as boolean | null,
      }
    }

    const videoId = params.videoId
    const subtitleId = params.subtitleId
    const bucketId = params.bucketId
    const fileId = params.fileId
    const name = params.name
    const code = params.code
    const xdefault = params.xdefault

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof subtitleId === 'undefined' || subtitleId === '') {
      throw new AppwriteException('Missing required parameter: "subtitleId"')
    }
    const apiPath = '/videos/{videoId}/subtitles/{subtitleId}'
      .replace('{videoId}', encodeURIComponent(videoId))
      .replace('{subtitleId}', encodeURIComponent(subtitleId))
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

    return this.client.call('patch', uri, apiHeaders, apiPayload)
  }

  /**
   * Delete a subtitle track by its unique ID, along with its packaged segments.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @param {string} params.subtitleId - Subtitle unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   */
  deleteSubtitle(params: { videoId: string; subtitleId: string }): Promise<{}>
  /**
   * Delete a subtitle track by its unique ID, along with its packaged segments.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @param {string} subtitleId - Subtitle unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<{}>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  deleteSubtitle(videoId: string, subtitleId: string): Promise<{}>
  deleteSubtitle(
    paramsOrFirst: { videoId: string; subtitleId: string } | string,
    ...rest: [string?]
  ): Promise<{}> {
    let params: { videoId: string; subtitleId: string }

    if (
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
    ) {
      params = (paramsOrFirst || {}) as {
        videoId: string
        subtitleId: string
      }
    } else {
      params = {
        videoId: paramsOrFirst as string,
        subtitleId: rest[0] as string,
      }
    }

    const videoId = params.videoId
    const subtitleId = params.subtitleId

    if (typeof videoId === 'undefined' || videoId === '') {
      throw new AppwriteException('Missing required parameter: "videoId"')
    }
    if (typeof subtitleId === 'undefined' || subtitleId === '') {
      throw new AppwriteException('Missing required parameter: "subtitleId"')
    }
    const apiPath = '/videos/{videoId}/subtitles/{subtitleId}'
      .replace('{videoId}', encodeURIComponent(videoId))
      .replace('{subtitleId}', encodeURIComponent(subtitleId))
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
   * Get the WebVTT sprite timeline for a video, used to render scrubbing thumbnails in a player. Queue generation with the create-timeline endpoint, then poll this endpoint until it returns a WebVTT document.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<string>}
   */
  getTimeline(params: { videoId: string }): Promise<string>
  /**
   * Get the WebVTT sprite timeline for a video, used to render scrubbing thumbnails in a player. Queue generation with the create-timeline endpoint, then poll this endpoint until it returns a WebVTT document.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<string>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  getTimeline(videoId: string): Promise<string>
  getTimeline(paramsOrFirst: { videoId: string } | string): Promise<string> {
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
    const apiPath = '/videos/{videoId}/timeline'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
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
   * Queue sprite-sheet and WebVTT timeline generation for a video. The working copy must already be `ready`; otherwise the request fails with `video_not_ready` or `video_source_removed`. Audio-only sources have no video track and fail with `video_track_not_found`.
   *
   * The request is accepted immediately. Poll the get-timeline endpoint until it returns a WebVTT document instead of `video_timeline_not_found`.
   *
   *
   * @param {string} params.videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   */
  createTimeline(params: { videoId: string }): Promise<Video>
  /**
   * Queue sprite-sheet and WebVTT timeline generation for a video. The working copy must already be `ready`; otherwise the request fails with `video_not_ready` or `video_source_removed`. Audio-only sources have no video track and fail with `video_track_not_found`.
   *
   * The request is accepted immediately. Poll the get-timeline endpoint until it returns a WebVTT document instead of `video_timeline_not_found`.
   *
   *
   * @param {string} videoId - Video unique ID.
   * @throws {AppwriteException}
   * @returns {Promise<Video>}
   * @deprecated Use the object parameter style method for a better developer experience.
   */
  createTimeline(videoId: string): Promise<Video>
  createTimeline(paramsOrFirst: { videoId: string } | string): Promise<Video> {
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
    const apiPath = '/videos/{videoId}/timeline'.replace(
      '{videoId}',
      encodeURIComponent(videoId),
    )
    const apiPayload: Payload = {}
    const uri = new URL(this.client.config.endpoint + apiPath)

    const apiHeaders: { [header: string]: string } = {
      'X-Appwrite-Project': this.client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    }

    return this.client.call('post', uri, apiHeaders, apiPayload)
  }
}

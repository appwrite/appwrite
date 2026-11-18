import type { Locator, Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The Videos pages against the Videos API as the server returns it: profiles
 * listed one codec at a time, codecs with the outputs they support, captions
 * with a processing status, and a thumbnail timeline whose cues point at
 * `thumbnails/{id}`. The Appwrite API is mocked at the network layer and the
 * real console pages are asserted.
 */

const NOW = '2026-11-18T09:30:00.000+00:00'

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'buckets.read',
  'buckets.write',
  'videos.read',
  'videos.write',
  'project.videos.profiles.read',
  'project.videos.profiles.write',
] satisfies Models.Roles['scopes']

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Operator',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'demo@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Halden Media',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
  prefs: {},
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT_ID = 'proj0000000000000000001'
const VIDEO_ID = 'video000000000000000001'
const THUMBNAIL_ID = 'thumb000000000000000001'

const PROJECT = {
  $id: PROJECT_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Keynotes',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: [],
  authMethods: [],
}

const VIDEO = {
  $id: VIDEO_ID,
  $createdAt: NOW,
  $updatedAt: NOW,
  $permissions: [],
  bucketId: 'media',
  fileId: 'file000000000000000001',
  name: 'Launch keynote',
  size: 52_428_800,
  format: 'mp4',
  duration: 10_000,
  width: 1920,
  height: 1080,
  aspectRatio: '16:9',
  videoCodec: 'h264',
  videoFormat: 'AVC',
  videoFormatProfile: 'High',
  videoBitRate: 4500,
  videoFrameRate: '30',
  videoFrameRateMode: 'CFR',
  audioCodec: 'aac',
  audioFormat: 'AAC',
  audioBitRate: 128,
  audioSampleRate: '48000',
  timelineStatus: 'ready',
  timelineError: '',
} satisfies Models.Video

const CODECS = [
  { $id: 'h264', name: 'H.264', outputs: ['hls', 'dash', 'cmaf'] },
  { $id: 'vp9', name: 'VP9', outputs: ['dash'] },
] satisfies Models.VideoCodec[]

const PROFILES = [
  {
    $id: 'profile00000000000000001',
    $createdAt: NOW,
    $updatedAt: NOW,
    name: '720p',
    codec: 'h264',
    width: 1280,
    height: 720,
    videoBitRate: 2500,
    audioBitRate: 128,
  },
  {
    $id: 'profile00000000000000002',
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Archive VP9',
    codec: 'vp9',
    width: 1920,
    height: 1080,
    videoBitRate: 4000,
    audioBitRate: 128,
  },
] satisfies Models.VideoProfile[]

const RENDITIONS = [
  {
    $id: 'rendition000000000000001',
    $createdAt: NOW,
    $updatedAt: NOW,
    videoId: VIDEO_ID,
    profileId: PROFILES[0].$id,
    name: '720p',
    startedAt: NOW,
    endedAt: NOW,
    width: 1280,
    height: 720,
    videoBitRate: 2500,
    audioBitRate: 128,
    targetDuration: '6',
    status: 'ready',
    error: '',
    progress: '100',
    output: 'cmaf',
    codec: 'h264',
  },
] satisfies Models.VideoRendition[]

const CAPTION_ERROR = 'Caption file is not valid WebVTT or SubRip.'

const CAPTIONS = [
  {
    $id: 'caption00000000000000001',
    $createdAt: NOW,
    $updatedAt: NOW,
    videoId: VIDEO_ID,
    bucketId: 'media',
    fileId: 'file000000000000000002',
    name: 'English',
    code: 'eng',
    default: true,
    embedded: false,
    targetDuration: '6',
    status: 'ready',
    error: '',
  },
  {
    $id: 'caption00000000000000002',
    $createdAt: NOW,
    $updatedAt: NOW,
    videoId: VIDEO_ID,
    bucketId: 'media',
    fileId: 'file000000000000000003',
    name: 'Hebrew',
    code: 'heb',
    default: false,
    embedded: false,
    targetDuration: '6',
    status: 'error',
    error: CAPTION_ERROR,
  },
] satisfies Models.VideoCaption[]

/** As the server sends it: relative cues on the timeline URL, carrying `project`. */
const TIMELINE = `WEBVTT

00:00:00.000 --> 00:00:05.000
thumbnails/${THUMBNAIL_ID}?project=${PROJECT_ID}#xywh=0,0,160,90

00:00:05.000 --> 00:00:10.000
thumbnails/${THUMBNAIL_ID}?project=${PROJECT_ID}#xywh=160,0,160,90
`

/** 1×1 PNG. */
const THUMBNAIL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
  'base64',
)

function corsHeaders(route: Route): Record<string, string> {
  const request = route.request()
  const origin = request.headers()['origin'] ?? 'http://localhost:4173'
  const requested = request.headers()['access-control-request-headers']
  return {
    'access-control-allow-origin': origin,
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      requested ??
      'content-type,x-appwrite-project,x-appwrite-response-format,x-appwrite-mode',
  }
}

type Calls = {
  profileLists: URL[]
  profileCreates: Request[]
  thumbnails: URL[]
}

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  video: Models.Video = VIDEO,
): Promise<Calls> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = { profileLists: [], profileCreates: [], thumbnails: [] }

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()

    const headers = corsHeaders(route)
    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
    const method = request.method()

    if (apiPath === '/videos/codecs') {
      return json(200, { total: CODECS.length, codecs: CODECS })
    }
    if (apiPath === '/project/videos/profiles' && method === 'GET') {
      calls.profileLists.push(url)
      // The API lists one codec per request and defaults to h264.
      const codec = url.searchParams.get('codec') || 'h264'
      const profiles = PROFILES.filter((profile) => profile.codec === codec)
      return json(200, { total: profiles.length, profiles })
    }
    if (apiPath === '/project/videos/profiles' && method === 'POST') {
      calls.profileCreates.push(request)
      const body = request.postDataJSON() as Record<string, unknown>
      return json(201, {
        ...PROFILES[0],
        $id: 'profile00000000000000003',
        ...body,
      })
    }
    if (apiPath === '/videos') {
      return json(200, { total: 1, videos: [video] })
    }
    if (apiPath === `/videos/${VIDEO_ID}`) return json(200, video)
    if (apiPath === `/videos/${VIDEO_ID}/renditions`) {
      return json(200, { total: RENDITIONS.length, renditions: RENDITIONS })
    }
    if (apiPath === `/videos/${VIDEO_ID}/captions`) {
      return json(200, { total: CAPTIONS.length, captions: CAPTIONS })
    }
    if (apiPath === `/videos/${VIDEO_ID}/timelines`) {
      if (video.timelineStatus !== 'ready') {
        return json(404, {
          message: 'Video timelines not found.',
          code: 404,
          type: 'video_timelines_not_found',
          version: '1.0',
        })
      }
      return route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'text/vtt' },
        body: TIMELINE,
      })
    }
    if (apiPath === `/videos/${VIDEO_ID}/thumbnails/${THUMBNAIL_ID}`) {
      calls.thumbnails.push(url)
      return route.fulfill({
        status: 200,
        headers: { ...headers, 'content-type': 'image/png' },
        body: THUMBNAIL,
      })
    }

    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') return json(200, ACCOUNT.prefs)
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '1.8.0' })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, { roles: OWNER_ROLES, scopes: OWNER_SCOPES })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, ORGANIZATION)
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === `/projects/${PROJECT_ID}` ||
      apiPath === `/organization/projects/${PROJECT_ID}` ||
      apiPath === `/projects/${PROJECT_ID}/console-access` ||
      apiPath === '/project'
    )
      return json(200, PROJECT)
    if (apiPath === '/storage/buckets')
      return json(200, { total: 0, buckets: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })

  return calls
}

/** Videos ship behind a console feature flag; turn it on for these specs. */
async function enableVideos(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({ id: 'self-hosted', features: { videos: true } }),
    )
  })
}

async function open(page: Page, path: string, ready: (page: Page) => Locator) {
  await page.goto(`/projects/${PROJECT_ID}/videos${path}`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(ready(page)).toBeVisible({ timeout: 60_000 })
}

/** Full-page screenshot once the console's loading overlay is gone. */
async function screenshot(page: Page, name: string) {
  await expect(page.locator('[data-fullscreen-loader]')).toBeHidden()
  await page.screenshot({
    path: test.info().outputPath(name),
    fullPage: true,
  })
}

const profileRow = (page: Page, name: string) =>
  page.getByRole('row', { name: new RegExp(`Select profile ${name}`) })

test.describe('videos (mocked API)', () => {
  test('lists the profiles of every enabled codec', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page)
    await open(page, '/profiles', (page) => profileRow(page, '720p'))

    await expect(profileRow(page, 'Archive VP9')).toBeVisible()
    await expect(
      profileRow(page, 'Archive VP9').getByRole('cell', {
        name: 'vp9',
        exact: true,
      }),
    ).toBeVisible()
    await expect(
      profileRow(page, '720p').getByRole('cell', { name: 'h264', exact: true }),
    ).toBeVisible()
    await expect
      .poll(() =>
        calls.profileLists.map((url) => url.searchParams.get('codec')).sort(),
      )
      .toEqual(expect.arrayContaining(['h264', 'vp9']))

    await screenshot(page, 'videos-profiles.png')
  })

  test('creates a profile with the chosen codec', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page)
    await open(page, '/profiles', (page) => profileRow(page, '720p'))

    await page.getByRole('button', { name: 'Create profile' }).first().click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('Codec', { exact: true })).toBeVisible()
    await dialog.getByRole('radio', { name: /VP9/ }).click()
    await page.screenshot({
      path: test.info().outputPath('videos-profile-codec.png'),
    })
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()

    await expect.poll(() => calls.profileCreates.length).toBe(1)
    expect(calls.profileCreates[0].postDataJSON()).toMatchObject({
      codec: 'vp9',
    })
  })

  test('shows the timeline from its thumbnail sheets', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page)
    await open(page, `/${VIDEO_ID}/timeline`, (page) =>
      page.getByText('Timeline file', { exact: true }),
    )

    await expect(page.getByText('Sprite sheets')).toBeVisible()
    await expect.poll(() => calls.thumbnails.length).toBeGreaterThan(0)
    expect(calls.thumbnails[0].searchParams.get('project')).toBe(PROJECT_ID)
    await expect(page.getByText('Set as preview')).toHaveCount(0)

    await screenshot(page, 'videos-timeline.png')
  })

  test('reports a timeline that failed', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page, {
      ...VIDEO,
      timelineStatus: 'error',
      timelineError: 'ffmpeg exited with code 1',
    })
    await open(page, `/${VIDEO_ID}/timeline`, (page) =>
      page.getByText('Timeline generation failed', { exact: true }),
    )

    await expect(page.getByText('ffmpeg exited with code 1')).toBeVisible()

    await screenshot(page, 'videos-timeline-error.png')
  })

  test('shows an audio-only video has no timeline', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page, {
      ...VIDEO,
      width: 0,
      height: 0,
      videoCodec: '',
      timelineStatus: 'skipped',
    })
    await open(page, `/${VIDEO_ID}/timeline`, (page) =>
      page.getByText('No timeline for this video', { exact: true }),
    )
  })

  test('shows why a caption failed', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    await open(page, `/${VIDEO_ID}/captions`, (page) =>
      page.getByRole('link', { name: 'Hebrew', exact: true }),
    )

    const failed = page.getByText('Failed', { exact: true })
    await expect(failed).toBeVisible()
    await failed.hover()
    await expect(page.getByRole('tooltip')).toContainText(CAPTION_ERROR)

    await page.screenshot({
      path: test.info().outputPath('videos-captions.png'),
    })
  })
})

import { readFileSync } from 'node:fs'
import { gunzipSync } from 'node:zlib'
import type { Locator, Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * The Videos pages against the Videos API as the server returns it: profiles
 * listed across codecs, codecs with the outputs they support, captions
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

/** Values of a `Query.equal('codec', [...])`; the SDK sends `queries[0]`, `queries[1]`, … */
function codecFilter(url: URL): string[] | undefined {
  return [...url.searchParams]
    .filter(([key]) => key.startsWith('queries['))
    .map(([, query]) => JSON.parse(query))
    .find((query) => query.method === 'equal' && query.attribute === 'codec')
    ?.values
}

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

/** Files under `fixtures/videos`, made with ffmpeg. */
const fixture = (name: string) =>
  readFileSync(new URL(`./fixtures/videos/${name}`, import.meta.url))

/** 320×90 sprite sheet: a red frame, then a blue frame (the two cues). */
const THUMBNAIL = fixture('sprite.png')

/** One second of 96×54 VP9 in fMP4 HLS (the test browser has no H.264). */
const STREAM: Record<string, { body: Buffer; type: string }> = {
  'master.m3u8': {
    body: fixture('stream.m3u8'),
    type: 'application/vnd.apple.mpegurl',
  },
  'init.mp4': { body: fixture('init.mp4'), type: 'video/mp4' },
  'segment0.m4s': { body: fixture('segment0.m4s'), type: 'video/iso.segment' },
}

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
  bucketLists: URL[]
  sourceViews: URL[]
  manifests: URL[]
  videoUpdates: Record<string, unknown>[]
}

/** A preset the mocked server refuses, to cover a partly failed batch. */
const REJECTED_PRESET = '1440p'

/** Every `/v1` call, on any region host, is answered here; the app itself is served live. */
async function mockAppwriteApi(
  page: Page,
  video: Models.Video = VIDEO,
  renditions: Models.VideoRendition[] = RENDITIONS,
  profiles: Models.VideoProfile[] = PROFILES,
  servePlayableStream = false,
  visitorStatus: 401 | 404 = 404,
): Promise<Calls> {
  const profileStore = [...profiles]
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const calls: Calls = {
    profileLists: [],
    profileCreates: [],
    thumbnails: [],
    bucketLists: [],
    sourceViews: [],
    manifests: [],
    videoUpdates: [],
  }

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
      // The API lists every codec; the Console filters with `Query.equal('codec', [...])`.
      const codecs = codecFilter(url)
      const listed = codecs
        ? profileStore.filter((profile) => codecs.includes(profile.codec))
        : profileStore
      return json(200, { total: listed.length, profiles: listed })
    }
    if (apiPath === '/project/videos/profiles' && method === 'POST') {
      calls.profileCreates.push(request)
      const body = request.postDataJSON() as Record<string, unknown>
      if (body.name === REJECTED_PRESET) {
        return json(409, {
          message: 'Profile already exists.',
          code: 409,
          type: 'video_profile_already_exists',
          version: '1.0',
        })
      }
      const created = {
        ...PROFILES[0],
        $id: `profile${String(profileStore.length + 1).padStart(17, '0')}`,
        ...body,
      } as Models.VideoProfile
      profileStore.push(created)
      return json(201, created)
    }
    if (apiPath === '/videos') {
      return json(200, { total: 1, videos: [video] })
    }
    if (apiPath === `/videos/${VIDEO_ID}` && method !== 'GET') {
      const body = request.postDataJSON() as Record<string, unknown>
      calls.videoUpdates.push(body)
      video = {
        ...video,
        name: String(body.name),
        $permissions: (body.permissions as string[]) ?? video.$permissions,
      }
      return json(200, video)
    }
    if (apiPath === `/videos/${VIDEO_ID}`) return json(200, video)
    if (apiPath === `/videos/${VIDEO_ID}/renditions`) {
      return json(200, { total: renditions.length, renditions })
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

    if (apiPath.startsWith(`/videos/${VIDEO_ID}/outputs/`)) {
      calls.manifests.push(url)
      // The video is private: a visitor's request is refused as not found.
      if (url.searchParams.get('mode') !== 'admin') {
        return visitorStatus === 401
          ? json(401, {
              message: 'The current user is not authorized.',
              code: 401,
              type: 'general_unauthorized_scope',
              version: '1.0',
            })
          : json(404, {
              message: 'Video with the requested ID could not be found.',
              code: 404,
              type: 'video_not_found',
              version: '1.0',
            })
      }
      // Members get a real stream for the private-video player page; other
      // pages leave it pending so the player stays loading, with controls.
      const file = STREAM[url.pathname.split('/').pop() ?? '']
      if (file && servePlayableStream) {
        return route.fulfill({
          status: 200,
          headers: { ...headers, 'content-type': file.type },
          body: file.body,
        })
      }
      return
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
    if (apiPath === '/storage/buckets') {
      calls.bucketLists.push(url)
      return json(200, { total: 0, buckets: [] })
    }
    if (
      apiPath ===
      `/storage/buckets/${video.bucketId}/files/${video.fileId}/view`
    ) {
      calls.sourceViews.push(url)
      return route.fulfill({ status: 200, headers, body: '' })
    }

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
    // Every enabled codec is filtered through queries, not the removed `codec` param.
    await expect
      .poll(() =>
        calls.profileLists.map((url) => ({
          codec: url.searchParams.get('codec'),
          codecs: codecFilter(url),
        })),
      )
      .toContainEqual({ codec: null, codecs: ['h264', 'vp9'] })

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
    // The saved profile is listed under its codec once the dialog closes.
    await expect(dialog).toBeHidden()
    const created = String(calls.profileCreates[0].postDataJSON().name)
    await expect(
      page
        .getByRole('row', { name: new RegExp(`Select profile ${created}`) })
        .filter({ has: page.getByRole('cell', { name: 'vp9', exact: true }) }),
    ).toBeVisible()
  })

  test('creates several preset profiles at once', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page)
    await open(page, '/profiles', (page) => profileRow(page, '720p'))

    await page.getByRole('button', { name: 'Create profile' }).first().click()
    const dialog = page.getByRole('dialog')
    // 720p is selected by default; add two more.
    await expect(dialog.getByRole('checkbox', { name: /720p/ })).toBeChecked()
    await dialog.getByRole('checkbox', { name: /360p/ }).click()
    await dialog
      .getByRole('checkbox', { name: new RegExp(REJECTED_PRESET) })
      .click()
    await page.screenshot({
      path: test.info().outputPath('videos-profile-presets.png'),
    })
    await dialog
      .getByRole('button', { name: 'Create (3)', exact: true })
      .click()

    await expect.poll(() => calls.profileCreates.length).toBe(3)
    expect(
      calls.profileCreates.map((request) => request.postDataJSON().name).sort(),
    ).toEqual(['1440p', '360p', '720p'])
    // The rejected preset stays selected so it can be retried.
    await expect(page.getByText('2 profiles created')).toBeVisible()
    await expect(dialog).toBeVisible()
    await expect(
      dialog.getByRole('checkbox', { name: new RegExp(REJECTED_PRESET) }),
    ).toBeChecked()
    await expect(
      dialog.getByRole('checkbox', { name: /360p/ }),
    ).not.toBeChecked()
  })

  test('locks the player until a rendition is ready', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page, VIDEO, [
      { ...RENDITIONS[0], status: 'started', progress: '40', endedAt: '' },
    ])
    await open(page, `/${VIDEO_ID}`, (page) =>
      page.locator('[data-player-state="locked"]'),
    )

    await expect(
      page.getByText(
        'Renditions are still encoding. Playback starts when one is ready.',
      ),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: /^Play/ })).toHaveCount(0)
    expect(calls.sourceViews).toHaveLength(0)

    await screenshot(page, 'videos-player-locked.png')
  })

  test('keeps the overview player size for a letterboxed video', async ({
    page,
  }) => {
    await enableVideos(page)
    // A 2.39:1 source in 16:9 renditions decodes as a padded 16:9 frame.
    await mockAppwriteApi(
      page,
      { ...VIDEO, width: 1920, height: 804, aspectRatio: '2.39:1' },
      RENDITIONS,
      PROFILES,
      true,
    )
    await open(page, `/${VIDEO_ID}/settings`, (page) =>
      page.getByRole('switch', { name: 'Public playback' }),
    )
    await page.evaluate(() => {
      const heights: number[] = []
      ;(window as unknown as { heights: number[] }).heights = heights
      const sample = () => {
        const frame = document.querySelector('video')?.parentElement
        if (frame)
          heights.push(Math.round(frame.getBoundingClientRect().height))
        requestAnimationFrame(sample)
      }
      sample()
    })

    await page
      .locator(`a[href$="/videos/${VIDEO_ID}"][data-status]`)
      .first()
      .click()
    const video = page.locator('video')
    await expect
      .poll(() => video.evaluate((node: HTMLVideoElement) => node.videoHeight))
      .toBeGreaterThan(0)
    await page.waitForTimeout(500)

    const heights = await page.evaluate(
      () => (window as unknown as { heights: number[] }).heights,
    )
    expect(heights.length).toBeGreaterThan(0)
    expect(new Set(heights).size).toBe(1)
  })

  test('keeps the player size when the stream loads', async ({ page }) => {
    await enableVideos(page)
    // Rendition sizes round, so the 16:9 stream is a hair off the source
    // ratio. The frame keeps the source ratio it rendered with first.
    await mockAppwriteApi(
      page,
      { ...VIDEO, width: 1000, height: 560 },
      RENDITIONS,
      PROFILES,
      true,
    )
    await open(page, `/${VIDEO_ID}`, (page) => page.locator('video'))
    const video = page.locator('video')
    await expect
      .poll(() => video.evaluate((node: HTMLVideoElement) => node.readyState))
      .toBeGreaterThanOrEqual(1)

    const frame = (await video.boundingBox())!
    expect(Math.abs(frame.height - (frame.width * 560) / 1000)).toBeLessThan(1)
  })

  test('shows encoding progress on every video tab', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page, VIDEO, [
      { ...RENDITIONS[0], status: 'started', progress: '40', endedAt: '' },
      {
        ...RENDITIONS[0],
        $id: 'rendition000000000000002',
        name: '1080p',
        width: 1920,
        height: 1080,
      },
    ])
    const progress = page.getByText('Processing renditions')
    const link = page.getByRole('link', { name: 'View renditions' })

    await open(page, `/${VIDEO_ID}`, () => progress)
    await expect(link).toBeVisible()
    await screenshot(page, 'videos-progress-overview.png')

    await open(page, `/${VIDEO_ID}/timeline`, () => progress)
    await expect(link).toBeVisible()

    await open(page, `/${VIDEO_ID}/renditions`, () => progress)
    await expect(link).toHaveCount(0)
  })

  test('shows rendition status right after the ID', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    const headers = page.getByRole('columnheader')

    await open(page, `/${VIDEO_ID}/renditions`, () =>
      headers.filter({ hasText: 'Status' }),
    )

    const names = (await headers.allInnerTexts()).map((text) => text.trim())
    const id = names.indexOf('ID')
    expect(id).toBeGreaterThanOrEqual(0)
    expect(names[id + 1]).toBe('Status')
  })

  test('docks a small player while scrolling the overview', async ({
    page,
  }) => {
    await enableVideos(page)
    await page.setViewportSize({ width: 1280, height: 720 })
    await mockAppwriteApi(page)
    await open(page, `/${VIDEO_ID}`, (page) => page.locator('video'))
    const player = page.locator('[data-player-docked]')
    await expect(player).toHaveCount(0)

    const video = page.locator('video')
    const fullHeight = (await video.boundingBox())!.height
    // Scroll the page area that holds the player.
    const scrollBy = (dy: number) =>
      video.evaluate((node, dy) => {
        let el = node.parentElement
        while (
          el &&
          !['auto', 'scroll'].includes(getComputedStyle(el).overflowY)
        )
          el = el.parentElement
        el?.scrollBy(0, dy)
      }, dy)

    // The inspector below loads after the player; scroll once there is room.
    await expect
      .poll(async () => {
        await scrollBy(600)
        return player.count()
      })
      .toBe(1)
    await expect
      .poll(async () => (await video.boundingBox())!.height)
      .toBeLessThan(fullHeight / 2)
    // Stays pinned to the top of the scroll area. Measure once the docking
    // slide-in has finished, or the 4px slide reads as the player moving.
    await player.evaluate((node) =>
      Promise.all(node.getAnimations().map((animation) => animation.finished)),
    )
    const docked = (await player.boundingBox())!
    await scrollBy(300)
    await expect
      .poll(async () => (await player.boundingBox())!.y)
      .toBeCloseTo(docked.y, 0)
    // The controls sit beside the picture, not over it.
    const play = player.getByRole('button', { name: 'Play', exact: true })
    await expect(play).toBeVisible()
    expect((await play.boundingBox())!.x).toBeGreaterThan(
      (await video.boundingBox())!.x,
    )
    await screenshot(page, 'videos-player-docked.png')

    await scrollBy(-5000)
    await expect(player).toHaveCount(0)
  })

  for (const status of [404, 401] as const) {
    test(`lets a project member watch a private video on the player page (${status})`, async ({
      page,
    }) => {
      await enableVideos(page)
      const calls = await mockAppwriteApi(
        page,
        VIDEO,
        RENDITIONS,
        PROFILES,
        true,
        status,
      )
      await page.goto(`/player/${VIDEO_ID}?project=${PROJECT_ID}`, {
        waitUntil: 'domcontentloaded',
      })

      await expect(
        page.getByText(
          'Visitors get an error here. You can watch because you are a project member.',
        ),
      ).toBeVisible({ timeout: 60_000 })
      expect(
        calls.manifests.some((url) => url.searchParams.get('mode') !== 'admin'),
      ).toBe(true)
      expect(
        calls.manifests.some((url) => url.searchParams.get('mode') === 'admin'),
      ).toBe(true)
      await expect(page.getByText(/manifestLoadError/)).toHaveCount(0)
      // The Console stream actually plays.
      await expect
        .poll(() =>
          page
            .locator('video')
            .evaluate((video: HTMLVideoElement) => video.readyState),
        )
        .toBeGreaterThanOrEqual(2)
      await page.screenshot({
        path: test.info().outputPath('videos-player-private.png'),
      })
    })
  }

  test('warns that a video is private', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    const warning = page.getByText('This video is private', { exact: true })

    await open(page, `/${VIDEO_ID}`, () => warning)
    await expect(
      page.getByText('Only project members and API keys can watch it.', {
        exact: false,
      }),
    ).toBeVisible()
    await expect(page.getByRole('button', { name: 'Make public' })).toHaveCount(
      0,
    )
    await screenshot(page, 'videos-private-warning.png')
  })

  test('opens the video permissions from the private warning', async ({
    page,
  }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page, {
      ...VIDEO,
      $permissions: ['read("users")', 'update("users")'],
    })
    const warning = page.getByText('This video is private', { exact: true })

    await open(page, `/${VIDEO_ID}`, () => warning)
    await expect(
      page.getByText(
        'Only signed-in users with read access can watch it in your app.',
        { exact: false },
      ),
    ).toBeVisible()
    await page.getByRole('link', { name: 'Permissions' }).click()

    const toggle = page.getByRole('switch', { name: 'Public playback' })
    await expect(toggle).not.toBeChecked()
    await screenshot(page, 'videos-settings-access.png')
    await toggle.click()

    await expect(toggle).toBeChecked()
    await expect(warning).toHaveCount(0)
    expect(calls.videoUpdates).toEqual([
      {
        name: VIDEO.name,
        permissions: ['read("users")', 'update("users")', 'read("any")'],
      },
    ])
  })

  test('shows no private warning for a public video', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page, { ...VIDEO, $permissions: ['read("any")'] })

    await open(page, `/${VIDEO_ID}/settings`, (page) =>
      page.getByRole('switch', { name: 'Public playback' }),
    )

    await expect(
      page.getByRole('switch', { name: 'Public playback' }),
    ).toBeChecked()
    await expect(page.getByText('This video is private')).toHaveCount(0)
  })

  test('asks for a rendition when a video has none', async ({ page }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page, VIDEO, [])
    await open(page, `/${VIDEO_ID}`, (page) =>
      page.getByText('Create a rendition to play this video.'),
    )

    await expect(page.locator('[data-player-state="locked"]')).toBeVisible()
    expect(calls.sourceViews).toHaveLength(0)
  })

  test('reloads buckets each time the create wizard opens', async ({
    page,
  }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page)
    await open(page, '', (page) =>
      page.getByRole('button', { name: 'Create video' }).first(),
    )

    const createVideo = page
      .getByRole('button', { name: 'Create video' })
      .first()
    await createVideo.click()
    await expect(page.getByText('Destination bucket').first()).toBeVisible()
    await expect.poll(() => calls.bucketLists.length).toBeGreaterThan(0)
    const afterFirstOpen = calls.bucketLists.length

    await page.keyboard.press('Escape')
    await expect(page.getByText('Destination bucket')).toHaveCount(0)
    await createVideo.click()
    await expect(page.getByText('Destination bucket').first()).toBeVisible()
    await expect
      .poll(() => calls.bucketLists.length)
      .toBeGreaterThan(afterFirstOpen)
  })

  test('creates profiles from the wizard when there are none', async ({
    page,
  }) => {
    await enableVideos(page)
    const calls = await mockAppwriteApi(page, VIDEO, RENDITIONS, [])
    await open(page, '', (page) =>
      page.getByRole('button', { name: 'Create video' }).first(),
    )
    await page.getByRole('button', { name: 'Create video' }).first().click()

    await page.getByRole('button', { name: 'Create profiles' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('checkbox', { name: /360p/ }).click()
    await dialog
      .getByRole('button', { name: 'Create (2)', exact: true })
      .click()

    await expect.poll(() => calls.profileCreates.length).toBe(2)
    await expect(dialog).toBeHidden()
    // The new profiles show up in the wizard, selected.
    await expect(
      page.getByRole('checkbox', { name: 'Select all' }),
    ).toBeChecked()
    await expect(
      page.getByText('No profiles yet.', { exact: false }),
    ).toHaveCount(0)
    await screenshot(page, 'videos-wizard-profiles.png')
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

    // Each thumbnail shows its own frame of the sheet: red, then blue.
    const frames = page.locator(
      '[data-cue-index] [style*="background-position"]',
    )
    await expect(frames).toHaveCount(2)
    const colors = await frames.evaluateAll(async (nodes) => {
      const style = getComputedStyle(nodes[0])
      const src = style.backgroundImage.slice(5, -2)
      const image = new Image()
      image.crossOrigin = 'anonymous'
      image.src = src
      await image.decode()
      const canvas = document.createElement('canvas')
      canvas.width = image.naturalWidth
      canvas.height = image.naturalHeight
      const context = canvas.getContext('2d')!
      context.drawImage(image, 0, 0)
      return nodes.map((node) => {
        const box = getComputedStyle(node)
        const [x, y] = box.backgroundPosition
          .split(' ')
          .map((value) => -parseFloat(value))
        const [r, , b] = context.getImageData(
          x + parseFloat(box.width) / 2,
          y + parseFloat(box.height) / 2,
          1,
          1,
        ).data
        return r > b ? 'red' : 'blue'
      })
    })
    expect(colors).toEqual(['red', 'blue'])

    await screenshot(page, 'videos-timeline.png')
  })

  test('downloads the timeline file and thumbnails', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    await open(page, `/${VIDEO_ID}/timeline`, (page) =>
      page.getByText('Timeline file', { exact: true }),
    )
    const download = (card: string) =>
      page
        .locator('section')
        .filter({ has: page.getByRole('heading', { name: card }) })
        .getByRole('button', { name: 'Download' })

    const [vtt] = await Promise.all([
      page.waitForEvent('download'),
      download('Timeline file').click(),
    ])
    expect(vtt.suggestedFilename()).toBe('launch-keynote-timeline.vtt')
    expect(readFileSync(await vtt.path(), 'utf8')).toBe(TIMELINE)

    const [archive] = await Promise.all([
      page.waitForEvent('download'),
      download('Thumbnails').click(),
    ])
    expect(archive.suggestedFilename()).toBe('launch-keynote-thumbnails.tar.gz')
    const tar = gunzipSync(readFileSync(await archive.path()))
    const listing = tar.toString('latin1')
    expect(listing).toContain('timeline.vtt')
    expect(listing).toContain('sprite-1.png#xywh=160,0,160,90')
    // The sheet is stored byte for byte.
    expect(tar.includes(THUMBNAIL)).toBe(true)
  })

  test('skips a black opening frame for the poster', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    // Later routes win: the first sheet frame is black, the second detailed.
    await page.route(`**/v1/videos/${VIDEO_ID}/thumbnails/**`, (route) =>
      route.fulfill({
        status: 200,
        headers: { ...corsHeaders(route), 'content-type': 'image/png' },
        body: fixture('sprite-poster.png'),
      }),
    )
    await open(page, `/${VIDEO_ID}/settings`, (page) =>
      page.locator('aside [style*="background-position"]').first(),
    )

    await expect(
      page.locator('aside [style*="background-position"]').first(),
    ).toHaveCSS('background-position', '-160px 0px')
  })

  test('letterboxes list thumbnails in black', async ({ page }) => {
    await enableVideos(page)
    await mockAppwriteApi(page)
    const frame = page
      .locator(
        `main a[href$="/videos/${VIDEO_ID}"] [style*="background-position"]`,
      )
      .first()

    await open(page, '', () => frame)

    await expect(frame.locator('..')).toHaveCSS(
      'background-color',
      'rgb(0, 0, 0)',
    )
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

/**
 * Same TTFF markers as the Console stream inspector (useHlsPlayer + useQoeTracker):
 *   loadStartedAt  → player starts
 *   manifestLoadedAt → hls.js MANIFEST_PARSED
 *   firstFrameAt → video `loadeddata`
 *   startupMs = firstFrameAt - loadStartedAt
 *   Manifest hint = manifestLoadedAt - loadStartedAt
 */
import { chromium } from './apps/console/node_modules/playwright/index.mjs'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

const stream = JSON.parse(readFileSync('./tmp-ttff-stream.json', 'utf8'))
const hlsPath = resolve('apps/console/node_modules/hls.js/dist/hls.min.js')

const browser = await chromium.launch({ headless: true })
const page = await browser.newPage()

await page.route('**/*', async (route) => {
  const url = route.request().url()
  if (url.includes('/v1/videos/')) {
    const headers = {
      ...route.request().headers(),
      'x-appwrite-project': stream.projectId,
      'x-appwrite-key': stream.apiKey,
    }
    await route.continue({ headers })
    return
  }
  await route.continue()
})

await page.setContent(
  '<!doctype html><html><body><video id="v" muted playsinline></video></body></html>',
)
await page.addScriptTag({ path: hlsPath })

async function measureOnce(label) {
  return page.evaluate(async ({ masterUrl, label }) => {
    const video = document.getElementById('v')
    video.removeAttribute('src')
    video.load()

    const Hls = window.Hls
    if (!Hls?.isSupported()) {
      throw new Error('hls.js not supported in this browser')
    }

    // Tear down any previous instance
    if (window.__hls) {
      window.__hls.destroy()
      window.__hls = null
    }

    const loadStartedAt = Date.now()
    let manifestLoadedAt = null
    let firstFrameAt = null

    const result = await new Promise((resolve, reject) => {
      const timeout = setTimeout(
        () => reject(new Error(`${label}: timed out waiting for first frame`)),
        30000,
      )

      const onFirstFrame = () => {
        if (firstFrameAt) return
        firstFrameAt = Date.now()
        clearTimeout(timeout)
        video.removeEventListener('loadeddata', onFirstFrame)
        resolve(null)
      }
      video.addEventListener('loadeddata', onFirstFrame)

      const hls = new Hls({ enableWorker: true })
      window.__hls = hls
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        manifestLoadedAt = Date.now()
        video.play().catch(() => {})
      })
      hls.on(Hls.Events.ERROR, (_e, data) => {
        if (data.fatal) {
          clearTimeout(timeout)
          reject(
            new Error(
              `${label}: fatal hls error ${data.type}/${data.details} ${data.response?.code ?? ''}`,
            ),
          )
        }
      })
      hls.loadSource(masterUrl)
      hls.attachMedia(video)
    })

    void result

    return {
      label,
      startupMs: firstFrameAt - loadStartedAt,
      manifestMs: manifestLoadedAt - loadStartedAt,
      postManifestMs: firstFrameAt - manifestLoadedAt,
      loadStartedAt,
      manifestLoadedAt,
      firstFrameAt,
    }
  }, { masterUrl: stream.masterUrl, label })
}

// Warm once (matches inspector after a prior play), then timed run.
await measureOnce('warm')
const timed = await measureOnce('timed')

const report = {
  source: stream,
  engine: 'hls.js (playwright chromium)',
  matchesConsoleInspector: {
    startupMs: 'firstFrameAt - loadStartedAt (video loadeddata)',
    manifestMs: 'manifestLoadedAt - loadStartedAt (MANIFEST_PARSED)',
  },
  ...timed,
}

writeFileSync('./tmp-ttff-browser.json', JSON.stringify(report, null, 2))
console.log(JSON.stringify(report, null, 2))

await browser.close()

import { setWorkerUrl } from 'maplibre-gl'
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url'

// maplibre-gl 6 cannot find its worker inside a bundle, so Vite emits it and passes the URL.
// With CDN_ORIGIN set the URL is cross-origin; maplibre then starts the worker from a
// same-origin blob module that imports it, so the CDN only has to serve it with CORS.
setWorkerUrl(workerUrl)

export * from 'maplibre-gl'

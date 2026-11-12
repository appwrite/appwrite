export enum VideoOutput {
  Hls = 'hls',
  Dash = 'dash',
  Cmaf = 'cmaf',
}

export enum VideoRenditionStatus {
  Pending = 'pending',
  Started = 'started',
  Ended = 'ended',
  Uploading = 'uploading',
  Ready = 'ready',
  Error = 'error',
  Aborted = 'aborted',
}

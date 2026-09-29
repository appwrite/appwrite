#!/usr/bin/env bash
# Re-encode the Firewall promo for progressive / HLS delivery (requires ffmpeg or Docker).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INPUT="${1:-$ROOT/public/videos/firewall-trailer.mp4}"
OUT_DIR="$ROOT/public/videos/firewall-trailer"
FASTSTART="$ROOT/public/videos/firewall-trailer-faststart.mp4"
# Shorter segments = less data before playback starts (align keyframes to segment length).
HLS_SEGMENT_SECONDS="${HLS_SEGMENT_SECONDS:-2}"

if [[ ! -f "$INPUT" ]]; then
  echo "error: input not found: $INPUT" >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

if command -v ffmpeg >/dev/null 2>&1; then
  echo "Writing faststart MP4 fallback…"
  ffmpeg -y -i "$INPUT" -movflags +faststart -c copy "$FASTSTART"
  echo "Writing HLS (${HLS_SEGMENT_SECONDS}s segments, 1080p H.264)…"
  ffmpeg -y -i "$INPUT" \
    -vf "scale=-2:1080" \
    -c:v libx264 -preset fast -crf 26 -maxrate 3M -bufsize 6M \
    -force_key_frames "expr:gte(t,n_forced*${HLS_SEGMENT_SECONDS})" \
    -c:a aac -b:a 128k \
    -hls_time "$HLS_SEGMENT_SECONDS" -hls_playlist_type vod -hls_flags independent_segments \
    -hls_segment_filename "$OUT_DIR/seg_%03d.ts" \
    "$OUT_DIR/index.m3u8"
else
  echo "Using Docker ffmpeg…"
  docker run --rm -v "$ROOT/public/videos:/videos" jrottenberg/ffmpeg:6-alpine \
    -y -i "/videos/$(basename "$INPUT")" -movflags +faststart -c copy /videos/firewall-trailer-faststart.mp4
  docker run --rm -v "$ROOT/public/videos:/videos" jrottenberg/ffmpeg:6-alpine \
    -y -i "/videos/$(basename "$INPUT")" \
    -vf "scale=-2:1080" \
    -c:v libx264 -preset fast -crf 26 -maxrate 3M -bufsize 6M \
    -force_key_frames "expr:gte(t,n_forced*${HLS_SEGMENT_SECONDS})" \
    -c:a aac -b:a 128k \
    -hls_time "$HLS_SEGMENT_SECONDS" -hls_playlist_type vod -hls_flags independent_segments \
    -hls_segment_filename "/videos/firewall-trailer/seg_%03d.ts" \
    /videos/firewall-trailer/index.m3u8
fi

echo "Done. Stream: /videos/firewall-trailer/index.m3u8"
echo "MP4 fallback: /videos/firewall-trailer-faststart.mp4"

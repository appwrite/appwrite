#!/usr/bin/env bash
# Firewall promo delivery assets (requires ffmpeg or Docker).
#
# Default: remux only — faststart MP4 + HLS with -c copy (no generational loss).
# Set HLS_REENCODE=1 only if the source is not H.264/AAC and you accept a transcode.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INPUT="${1:-$ROOT/public/videos/firewall-trailer.mp4}"
OUT_DIR="$ROOT/public/videos/firewall-trailer"
FASTSTART="$ROOT/public/videos/firewall-trailer-faststart.mp4"
# Target segment length; with -c copy, splits occur at keyframes near this duration.
HLS_SEGMENT_SECONDS="${HLS_SEGMENT_SECONDS:-2}"
HLS_REENCODE="${HLS_REENCODE:-0}"

if [[ ! -f "$INPUT" ]]; then
  echo "error: input not found: $INPUT" >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

run_ffmpeg() {
  if command -v ffmpeg >/dev/null 2>&1; then
    ffmpeg "$@"
  else
    local args=("$@")
    # Map host paths under public/videos to /videos/... in the container.
    local mapped=()
    for arg in "${args[@]}"; do
      case "$arg" in
        "$ROOT/public/videos"/*)
          mapped+=("/videos/${arg#"$ROOT/public/videos"/}")
          ;;
        *)
          mapped+=("$arg")
          ;;
      esac
    done
    docker run --rm -v "$ROOT/public/videos:/videos" jrottenberg/ffmpeg:6-alpine \
      "${mapped[@]}"
  fi
}

echo "Writing faststart MP4 (stream copy, moov at head)…"
run_ffmpeg -y -i "$INPUT" -movflags +faststart -c copy "$FASTSTART"

if [[ "$HLS_REENCODE" == "1" ]]; then
  echo "Writing HLS (${HLS_SEGMENT_SECONDS}s segments, transcoded — use only when remux is impossible)…"
  run_ffmpeg -y -i "$INPUT" \
    -vf "scale=-2:1080" \
    -c:v libx264 -preset slow -crf 18 -maxrate 14M -bufsize 28M \
    -force_key_frames "expr:gte(t,n_forced*${HLS_SEGMENT_SECONDS})" \
    -c:a aac -b:a 192k \
    -hls_time "$HLS_SEGMENT_SECONDS" -hls_playlist_type vod -hls_flags independent_segments \
    -hls_segment_filename "$OUT_DIR/seg_%03d.ts" \
    "$OUT_DIR/index.m3u8"
else
  echo "Writing HLS (${HLS_SEGMENT_SECONDS}s target, stream copy — same quality as source)…"
  run_ffmpeg -y -i "$INPUT" -c copy \
    -hls_time "$HLS_SEGMENT_SECONDS" -hls_playlist_type vod -hls_flags independent_segments \
    -hls_segment_filename "$OUT_DIR/seg_%03d.ts" \
    "$OUT_DIR/index.m3u8"
fi

echo "Done. Progressive: /videos/firewall-trailer-faststart.mp4"
echo "HLS (optional): /videos/firewall-trailer/index.m3u8"

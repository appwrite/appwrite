#!/usr/bin/env bash
set -euo pipefail

# Publish the GitHub release for a deployed apps/console/<version> tag, so the
# deploy is announced and documented like any other release. Never marked
# latest: the repo's latest release is the server's.
: "${TAG:?Missing the apps/console/<version> tag}"
: "${VERSION:?Missing the console version}"

if gh release view "$TAG" >/dev/null 2>&1; then
  echo "Release $TAG already exists"
  exit 0
fi

# Notes are best effort: a commit whose PRs can't be listed after a few
# tries is left out rather than failing a release for a deploy already live.
pulls() {
  local sha=$1 attempt
  for attempt in 1 2 3; do
    if gh api "repos/$GH_REPO/commits/$sha/pulls" \
      --jq '.[] | select(.merged_at != null) | "* \(.title) by @\(.user.login) in \(.html_url)"'; then
      return 0
    fi
    sleep $((attempt * 5))
  done
  echo "::warning::Could not list the pull requests for $sha; leaving it out of the notes" >&2
}

previous=$(git tag --list 'apps/console/*' \
  | sed 's#^apps/console/##' \
  | { grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' || true; } \
  | sort -V \
  | awk -v version="$VERSION" '$0 == version { print previous; exit } { previous = $0 }')

notes=$(mktemp)
trap 'rm -f "$notes"' EXIT
if [[ -n "$previous" ]]; then
  # First-parent commits on main that touched the console, as their PRs, so
  # the server and package changes between the two tags stay out.
  changes=$(git log --first-parent --format=%H "apps/console/$previous..$TAG" -- apps/console \
    | while read -r sha; do pulls "$sha"; done \
    | awk '!seen[$0]++')
  {
    echo "## What's Changed"
    echo
    echo "${changes:-No console changes since $previous.}"
    echo
    echo "**Full Changelog**: https://github.com/$GH_REPO/compare/apps/console/$previous...$TAG"
  } > "$notes"
else
  echo "First console release." > "$notes"
fi

gh release create "$TAG" \
  --verify-tag \
  --latest=false \
  --title "Console $VERSION" \
  --notes-file "$notes"

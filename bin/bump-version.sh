#!/usr/bin/env bash
#
# Bump the package's semver git tag so Composer can resolve the new version.
#
# Usage:
#   bin/bump-version.sh <major|minor|patch> [--dry-run] [--no-push]
#
# What it does:
#   1. Reads the latest vX.Y.Z tag (defaults to v0.0.0 if none exists).
#   2. Computes the next version for the requested bump level.
#   3. Moves the "## Unreleased" section of CHANGELOG.md (if present) under
#      the new version heading, stamped with today's date.
#   4. Commits the changelog change and creates an annotated git tag vX.Y.Z.
#   5. Pushes the commit and tag, unless --no-push is given.

set -euo pipefail

usage() {
    echo "Usage: $0 <major|minor|patch> [--dry-run] [--no-push]" >&2
    exit 1
}

[[ $# -ge 1 ]] || usage

LEVEL="$1"
shift

DRY_RUN=false
PUSH=true

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --no-push) PUSH=false ;;
        *) usage ;;
    esac
done

case "$LEVEL" in
    major|minor|patch) ;;
    *) usage ;;
esac

cd "$(git rev-parse --show-toplevel)"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Error: working tree is not clean. Commit or stash changes first." >&2
    exit 1
fi

LATEST_TAG=$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' | sort -V | tail -n1)
LATEST_TAG=${LATEST_TAG:-v0.0.0}

VERSION=${LATEST_TAG#v}
IFS='.' read -r MAJOR MINOR PATCH <<< "$VERSION"

case "$LEVEL" in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
NEW_TAG="v${NEW_VERSION}"

if git rev-parse "$NEW_TAG" >/dev/null 2>&1; then
    echo "Error: tag $NEW_TAG already exists." >&2
    exit 1
fi

echo "Bumping $LATEST_TAG -> $NEW_TAG ($LEVEL)"

CHANGELOG="CHANGELOG.md"
TODAY=$(date +%Y-%m-%d)

if [[ -f "$CHANGELOG" ]] && grep -qE '^#{2,3} Unreleased' "$CHANGELOG"; then
    if [[ "$DRY_RUN" == true ]]; then
        echo "[dry-run] Would rename 'Unreleased' to '### ${NEW_VERSION} - ${TODAY}' in $CHANGELOG"
    else
        sed -i '' -E "s/^#{2,3} Unreleased/### ${NEW_VERSION} - ${TODAY}/" "$CHANGELOG"
        git add "$CHANGELOG"
        git commit -m "chore: release ${NEW_TAG}"
    fi
else
    echo "Note: no '## Unreleased' section found in $CHANGELOG; skipping changelog update."
fi

if [[ "$DRY_RUN" == true ]]; then
    echo "[dry-run] Would create annotated tag $NEW_TAG"
    [[ "$PUSH" == true ]] && echo "[dry-run] Would push commit and tag to origin"
    exit 0
fi

git tag -a "$NEW_TAG" -m "Release ${NEW_TAG}"

if [[ "$PUSH" == true ]]; then
    git push origin HEAD
    git push origin "$NEW_TAG"
fi

echo "Done: $NEW_TAG"

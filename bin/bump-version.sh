#!/usr/bin/env bash
#
# bump-version.sh — Bump the plugin version, commit, and push to main.
#
# After this script pushes to main, the GitHub Actions workflow
# (.github/workflows/auto-release.yml) will automatically:
#   1. Detect the new version in myies-integration.php
#   2. Create a tagged GitHub Release (e.g. v1.0.19)
#   3. Attach a clean plugin zip to the release
#
# WordPress then picks up the new release via the built-in updater
# (includes/class-github-updater.php) within 6 hours, or immediately
# when "Check for updates" is clicked on the Plugins page.
#
# For private repos, add to wp-config.php:
#   define('MYIES_GITHUB_TOKEN', 'ghp_your_token');
#
# Usage:
#   ./bin/bump-version.sh           # bumps patch  (1.0.8 → 1.0.9)
#   ./bin/bump-version.sh minor     # bumps minor  (1.0.8 → 1.1.0)
#   ./bin/bump-version.sh major     # bumps major  (1.0.8 → 2.0.0)
#   ./bin/bump-version.sh 2.5.0     # sets exact version
#
set -euo pipefail

PLUGIN_FILE="myies-integration.php"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$ROOT_DIR/$PLUGIN_FILE"

if [ ! -f "$FILE" ]; then
    echo "Error: $PLUGIN_FILE not found at $ROOT_DIR" >&2
    exit 1
fi

# Read current version from the constant
CURRENT=$(grep -oP "define\('WICKET_INTEGRATION_VERSION',\s*'\\K[^']+" "$FILE")
if [ -z "$CURRENT" ]; then
    echo "Error: could not read current version from $PLUGIN_FILE" >&2
    exit 1
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

BUMP="${1:-patch}"

case "$BUMP" in
    patch)
        PATCH=$((PATCH + 1))
        NEW_VERSION="$MAJOR.$MINOR.$PATCH"
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        NEW_VERSION="$MAJOR.$MINOR.$PATCH"
        ;;
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        NEW_VERSION="$MAJOR.$MINOR.$PATCH"
        ;;
    *)
        # Treat argument as explicit version
        NEW_VERSION="$BUMP"
        ;;
esac

echo "Bumping version: $CURRENT → $NEW_VERSION"

# Update plugin header
sed -i "s/^ \* Version: $CURRENT/ * Version: $NEW_VERSION/" "$FILE"

# Update PHP constant
sed -i "s/define('WICKET_INTEGRATION_VERSION', '$CURRENT')/define('WICKET_INTEGRATION_VERSION', '$NEW_VERSION')/" "$FILE"

# Verify
UPDATED=$(grep -oP "define\('WICKET_INTEGRATION_VERSION',\s*'\\K[^']+" "$FILE")
if [ "$UPDATED" != "$NEW_VERSION" ]; then
    echo "Error: version update failed (got $UPDATED, expected $NEW_VERSION)" >&2
    exit 1
fi

echo "Updated $PLUGIN_FILE to $NEW_VERSION"

# Stage, commit, push
cd "$ROOT_DIR"
git add "$PLUGIN_FILE"
git commit -m "Bump plugin version to $NEW_VERSION"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "Pushing to origin/$BRANCH …"
git push -u origin "$BRANCH"

echo ""
echo "Done — version $NEW_VERSION pushed."
echo ""
echo "Next steps:"
echo "  1. Merge/push to main to trigger the Auto Release workflow."
echo "  2. GitHub Actions will create tag v$NEW_VERSION and attach a zip."
echo "  3. WordPress will detect the update automatically (or via 'Check for updates')."

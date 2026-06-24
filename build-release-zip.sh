#!/usr/bin/env bash
#
# Builds a clean kntnt-ai-visibility.zip containing only runtime files.
#
# The plugin bundles a runtime dependency (kntnt/html-to-markdown), so the build
# runs `composer install --no-dev --optimize-autoloader` inside a throwaway
# staging directory and ships the resulting vendor/. The working tree's own
# vendor/ is never touched. The asset name has no version segment so the
# GitHub Releases "latest/download" URL stays stable (see docs/adr/0005); the
# Updater identifies the asset by content type, not filename.
#
# With no arguments, the zip is written to dist/ in the repo root (created if
# missing); pass --output/--update/--create to choose a different destination.
#
# Requirements: zip, composer.
#   With --tag: git.
#   With --update/--create: gh (GitHub CLI).
#
# Exit codes:
#   0  success
#   1  usage error, missing tool, or build failure

set -euo pipefail

REPO="Kntnt/kntnt-ai-visibility"
PLUGIN_DIR="kntnt-ai-visibility"
ZIP_NAME="${PLUGIN_DIR}.zip"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Print usage and exit with the given code (default 0).
usage() {
	cat <<'HELP'
Usage:
  build-release-zip.sh [--output <path>]
  build-release-zip.sh --tag <tag> [--output <path>]
  build-release-zip.sh --tag <tag> --update
  build-release-zip.sh --tag <tag> --create
  build-release-zip.sh --help

Source:
  Without --tag, builds from the local working copy.
  With --tag <tag>, builds from the files at the given git tag.

Destination (defaults to dist/ in the repo root when none is given):
  --output <path>      Save the zip to <path>. A directory (or trailing /) saves
                       kntnt-ai-visibility.zip inside it; otherwise the last path
                       component is the filename. The parent must exist. Omit to
                       write ./dist/kntnt-ai-visibility.zip (dist/ is created).
  --update             Upload the zip to an existing GitHub release for <tag>,
                       replacing any existing zip asset. Requires --tag.
  --create             Create a new GitHub release for <tag> and upload the zip.
                       The tag must already exist. Requires --tag.

Examples:
  build-release-zip.sh
  build-release-zip.sh --output ~/Desktop/custom-name.zip
  build-release-zip.sh --tag 0.1.0 --output /tmp
  build-release-zip.sh --tag 0.1.0 --create
  build-release-zip.sh --tag 0.1.0 --update
HELP
	exit "${1:-0}"
}

# Parse the command-line arguments.
TAG=""
OUTPUT_PATH=""
RELEASE_ACTION=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--help | -h)
			usage 0
			;;
		--tag)
			[[ $# -lt 2 ]] && {
				echo "Error: --tag requires a value." >&2
				exit 1
			}
			TAG="$2"
			shift 2
			;;
		--output)
			[[ $# -lt 2 ]] && {
				echo "Error: --output requires a value." >&2
				exit 1
			}
			OUTPUT_PATH="$2"
			shift 2
			;;
		--update)
			[[ -n "$RELEASE_ACTION" ]] && {
				echo "Error: --update and --create are mutually exclusive." >&2
				exit 1
			}
			RELEASE_ACTION="update"
			shift
			;;
		--create)
			[[ -n "$RELEASE_ACTION" ]] && {
				echo "Error: --update and --create are mutually exclusive." >&2
				exit 1
			}
			RELEASE_ACTION="create"
			shift
			;;
		*)
			echo "Error: Unknown option: $1" >&2
			echo >&2
			usage 1
			;;
	esac
done

# With no destination given, default to building into dist/ in the repo root.
if [[ -z "$OUTPUT_PATH" && -z "$RELEASE_ACTION" ]]; then
	OUTPUT_PATH="$SCRIPT_DIR/dist"
	mkdir -p "$OUTPUT_PATH"
fi
if [[ -n "$OUTPUT_PATH" && -n "$RELEASE_ACTION" ]]; then
	echo "Error: --output and --${RELEASE_ACTION} cannot be combined." >&2
	exit 1
fi

# Validate that --update/--create are paired with --tag.
if [[ -n "$RELEASE_ACTION" && -z "$TAG" ]]; then
	echo "Error: --${RELEASE_ACTION} requires --tag." >&2
	exit 1
fi

# Resolve the output path: a directory gets the default filename; a file path's
# parent directory must already exist.
OUTPUT_FILE=""
if [[ -n "$OUTPUT_PATH" ]]; then
	if [[ -d "$OUTPUT_PATH" ]]; then
		OUTPUT_FILE="$(cd "$OUTPUT_PATH" && pwd)/$ZIP_NAME"
	elif [[ "$OUTPUT_PATH" == */ ]]; then
		echo "Error: Directory '${OUTPUT_PATH}' does not exist." >&2
		exit 1
	else
		parent_dir="$(dirname "$OUTPUT_PATH")"
		if [[ ! -d "$parent_dir" ]]; then
			echo "Error: Directory '${parent_dir}' does not exist." >&2
			exit 1
		fi
		OUTPUT_FILE="$(cd "$parent_dir" && pwd)/$(basename "$OUTPUT_PATH")"
	fi
fi

# Verify that the required tools are available.
MISSING=()
for cmd in zip composer; do
	command -v "$cmd" &>/dev/null || MISSING+=("$cmd")
done
[[ -n "$TAG" ]] && { command -v git &>/dev/null || MISSING+=("git"); }
[[ -n "$RELEASE_ACTION" ]] && { command -v gh &>/dev/null || MISSING+=("gh"); }
if [[ ${#MISSING[@]} -gt 0 ]]; then
	echo "Missing required tools: ${MISSING[*]}" >&2
	exit 1
fi

# Verify the tag and the target release state.
if [[ -n "$TAG" ]]; then
	if [[ -z "$(git -C "$SCRIPT_DIR" tag -l "$TAG")" ]]; then
		echo "Error: Tag '$TAG' does not exist." >&2
		echo "Create it first:  git tag $TAG && git push origin $TAG" >&2
		exit 1
	fi
	if [[ "$RELEASE_ACTION" == "update" ]] && ! gh release view "$TAG" --repo "$REPO" &>/dev/null; then
		echo "Error: Release '$TAG' does not exist. Use --create instead." >&2
		exit 1
	fi
	if [[ "$RELEASE_ACTION" == "create" ]] && gh release view "$TAG" --repo "$REPO" &>/dev/null; then
		echo "Error: Release '$TAG' already exists. Use --update instead." >&2
		exit 1
	fi
fi

# Runtime files and directories to keep in the release zip. Everything else
# (tests, CI, dev configs, composer manifests, dotfiles, this script) is dropped.
KEEP=(
	autoloader.php
	classes
	install.php
	kntnt-ai-visibility.php
	languages
	LICENSE
	README.md
	uninstall.php
	vendor
)

# Work in a temporary directory that is removed on any exit.
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

# Stage the source: either the tagged tree or the local working copy (without
# vendor/, node_modules/, .git/, or any previously built zip).
if [[ -n "$TAG" ]]; then
	echo "Source: git tag $TAG"
	git -C "$SCRIPT_DIR" archive --prefix="${PLUGIN_DIR}/" "$TAG" | tar -xf - -C "$TMPDIR"
else
	echo "Source: local working copy"
	rsync -a \
		--exclude='.git' \
		--exclude='vendor' \
		--exclude='node_modules' \
		--exclude='dist' \
		--exclude="$ZIP_NAME" \
		"$SCRIPT_DIR/" "$TMPDIR/$PLUGIN_DIR/"
fi

# Install the runtime dependencies into the staging tree so vendor/ ships only
# what the plugin needs at runtime.
echo "Running composer install --no-dev --optimize-autoloader"
composer install \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--quiet \
	--working-dir="$TMPDIR/$PLUGIN_DIR"

# Remove everything that is not in the keep list.
cd "$TMPDIR/$PLUGIN_DIR"
shopt -s dotglob
for entry in *; do
	keep=false
	for allowed in "${KEEP[@]}"; do
		if [[ "$entry" == "$allowed" ]]; then
			keep=true
			break
		fi
	done
	if [[ "$keep" == false ]]; then
		rm -rf "$entry"
		echo "  Removed: $entry"
	fi
done
shopt -u dotglob
cd "$TMPDIR"

# Create the zip with a single top-level plugin directory.
zip -qr "$ZIP_NAME" "$PLUGIN_DIR"
echo "Created: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"

# Deliver the zip to the requested destination.
if [[ -n "$OUTPUT_FILE" ]]; then
	cp "$ZIP_NAME" "$OUTPUT_FILE"
	echo "Saved: $OUTPUT_FILE"
fi

if [[ "$RELEASE_ACTION" == "create" ]]; then

	# Source the release body from the matching CHANGELOG.md section rather than
	# GitHub's auto-generated digest (docs/adr/0011). The tag is v-prefixed; the
	# changelog heading carries the bare version, so strip the leading `v`.
	version="${TAG#v}"
	notes_file="$TMPDIR/release-notes.md"
	awk -v ver="$version" '
		index($0, "## [" ver "]") == 1 { capture = 1; next }
		capture && /^## \[/ { exit }
		capture && /^\[[^][]+\]:[[:space:]]/ { exit }
		capture { print }
	' "$SCRIPT_DIR/CHANGELOG.md" > "$notes_file"

	# Use the changelog notes when the section had real content; otherwise fall
	# back to auto-generated notes so a release is never published note-less.
	if grep -q '[^[:space:]]' "$notes_file"; then
		printf '\n**Full changelog:** https://github.com/%s/blob/%s/CHANGELOG.md\n' "$REPO" "$TAG" >> "$notes_file"
		gh release create "$TAG" --title "$TAG" --notes-file "$notes_file" --repo "$REPO"
	else
		echo "Warning: no CHANGELOG section for ${version}; using auto-generated notes." >&2
		gh release create "$TAG" --title "$TAG" --generate-notes --repo "$REPO"
	fi
	echo "Created release: $TAG"
fi

if [[ "$RELEASE_ACTION" == "update" || "$RELEASE_ACTION" == "create" ]]; then

	# Replace an existing asset of the same name before uploading the new one.
	if gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name' | grep -qx "$ZIP_NAME"; then
		echo "Replacing existing $ZIP_NAME in release ${TAG}…"
		gh release delete-asset "$TAG" "$ZIP_NAME" --repo "$REPO" --yes
	fi
	gh release upload "$TAG" "$ZIP_NAME" --repo "$REPO"
	echo "Uploaded $ZIP_NAME to release $TAG"
fi

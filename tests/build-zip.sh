#!/usr/bin/env bash
# Builds the installable zip the same way the release workflow does: the plugin folder must be named
# "podpublisher" INSIDE the zip, otherwise WordPress installs it as a second, separate plugin
# instead of upgrading the one already installed (this went wrong with 0.17.0).
# Usage: tests/build-zip.sh [output.zip]   (default: podpublisher-<version>-test.zip in the current folder)
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$root/plugin/podpublisher.php" | head -n1 | tr -d '[:space:]')
out="${1:-$PWD/podpublisher-$version-test.zip}"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
cp -r "$root/plugin" "$stage/podpublisher"
rm -f "$out"
(cd "$stage" && zip -qr "$out" podpublisher)
first=$(unzip -Z1 "$out" | head -n1)
[ "$first" = "podpublisher/" ] || { echo "FAIL: zip must start with podpublisher/, got $first"; exit 1; }
unzip -Z1 "$out" | grep -qx "podpublisher/podpublisher.php" || { echo "FAIL: podpublisher/podpublisher.php missing"; exit 1; }
if unzip -Z1 "$out" | grep -v '^podpublisher/' | grep -q .; then echo "FAIL: files outside podpublisher/"; exit 1; fi
echo "$out"

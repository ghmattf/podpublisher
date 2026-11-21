#!/usr/bin/env bash
# Runs every check. Needs: php (and node 18+ once JS tests are added).
set -u
cd "$(dirname "$0")"
fail=0
step() { echo; echo "== $1"; shift; "$@" || fail=1; }
for f in ../plugin/*.php ../plugin/includes/*.php; do php -l "$f" >/dev/null || { echo "PHP syntax error: $f"; fail=1; }; done
for f in ../plugin/assets/js/*.js; do [ -e "$f" ] && { node --check "$f" || fail=1; }; done
step "Version agrees in plugin header, PODPUB_VERSION and readme.txt" bash -c '
  h=$(sed -n "s/^ \* Version:[[:space:]]*//p" ../plugin/podpublisher.php | head -n1 | tr -d "[:space:]")
  c=$(sed -n "s/^define( .PODPUB_VERSION., .\([0-9.]*\). );.*/\1/p" ../plugin/podpublisher.php | head -n1)
  r=$(sed -n "s/^Stable tag:[[:space:]]*//p" ../plugin/readme.txt | head -n1 | tr -d "[:space:]")
  echo "header=$h constant=$c readme=$r"
  [ -n "$h" ] && [ "$h" = "$c" ] && [ "$h" = "$r" ]'
step "Admin menu structure (stubbed WordPress)" php test_admin_menu.php
step "Folder names and podcast/episode folder layout" php test_storage.php
step "Podcast details, show art, feed XML and save flow" php test_feed_details.php
step "Episodes: audio, dates, folders, feed items, delete" php test_episodes.php
step "Admin pages render (podcast page, episode list and form)" php test_admin_render.php
step "Podcasting 2.0 tags: checking, feed XML, saving" php test_p20.php
step "Chunk D: chapters, transcripts, soundbites, alternate files, comments, status" php test_chunk_d.php
echo; [ $fail -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $fail

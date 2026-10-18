#!/bin/sh
# Gate 2 captures: today's site, the built site, and each new piece switched on.
#   capture-all.sh <built site root> <variants root prefix> <out>
cd "$(dirname "$0")/../browser"
REPO="$(cd ../.. && pwd)"; CAND="$1"; VAR="$2"; OUT="$3"
( node capture.js "$REPO" "$OUT/baseline" chromium && node capture.js "$CAND" "$OUT/candidate" chromium ) > "$OUT/chromium.log" 2>&1 &
( node capture.js "$REPO" "$OUT/baseline" webkit && node capture.js "$CAND" "$OUT/candidate" webkit ) > "$OUT/webkit.log" 2>&1 &
(
node capture.js "${VAR}seal-off" "$OUT/variants/seal-off" chromium,webkit Home,Services,Claim 375,1440
node capture.js "${VAR}notice-on" "$OUT/variants/notice-on" chromium,webkit Home,About,Services,FAQ,Contact,Claim 375,1024,1440
node capture.js "${VAR}reviews" "$OUT/variants/reviews" chromium,webkit Home 375,768,1440,1920
node capture.js "${VAR}brighter" "$OUT/variants/brighter" chromium,webkit Home,Services,FAQ,Claim 375,1440
node capture.js "${VAR}brightest" "$OUT/variants/brightest" chromium,webkit Home,Services,FAQ,Claim 375,1440
node capture.js "${VAR}states-12" "$OUT/variants/states-12" chromium,webkit Claim 375,768,1024,1280,1366,1440,1920
node capture.js "${VAR}phrases-3" "$OUT/variants/phrases-3" chromium,webkit Home 375,1920
node capture.js "${VAR}phrases-20" "$OUT/variants/phrases-20" chromium,webkit Home 375,1920
) > "$OUT/variants.log" 2>&1 &
wait
echo all-captured

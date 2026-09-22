#!/usr/bin/env bash
#
# Register the Plivo outbound trunk on our own LiveKit server.
#
# Everything it needs comes from .env, for two reasons: the SIP password never
# reaches the shell history or the process list, and the termination domain is
# written down once instead of being pasted into a JSON file that would then
# sit in the repo.
#
#   ./deployment/livekit/create-plivo-trunk.sh
#
# It prints a SIPTrunkID (ST_...). That is LIVEKIT_SIP_TRUNK_ID — put it in the
# same .env and redeploy. Run this again after rotating the SIP credential; the
# trunk id does not change, so nothing else has to move.
#
# See docs/plivo-go-live.md for the surrounding steps.
set -euo pipefail

cd "$(dirname "$0")/../.."
ENV_FILE="${ENV_FILE:-.env}"

[ -f "$ENV_FILE" ] || { echo "No $ENV_FILE here."; exit 1; }

# Read the file rather than sourcing it: .env holds values with spaces, '#' and
# shell metacharacters, and sourcing it would try to execute some of them.
env_get() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

SIP_USER=$(env_get PLIVO_SIP_USERNAME)
SIP_PASS=$(env_get PLIVO_SIP_PASSWORD)
DOMAIN=$(env_get PLIVO_TERMINATION_DOMAIN)
NUMBER=$(env_get SCREENING_FROM_NUMBER)
TRANSPORT=$(env_get PLIVO_SIP_TRANSPORT)
TRANSPORT=${TRANSPORT:-SIP_TRANSPORT_TLS}

missing=""
[ -n "$SIP_USER" ] || missing="$missing PLIVO_SIP_USERNAME"
[ -n "$SIP_PASS" ] || missing="$missing PLIVO_SIP_PASSWORD"
[ -n "$DOMAIN" ]   || missing="$missing PLIVO_TERMINATION_DOMAIN"
[ -n "$NUMBER" ]   || missing="$missing SCREENING_FROM_NUMBER"
[ -z "$missing" ] || { echo "Missing in $ENV_FILE:$missing"; exit 1; }

# lk defaults to LiveKit Cloud. A trunk created there is created successfully
# and never places one of our calls, so refuse rather than let that happen.
: "${LIVEKIT_URL:?export LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET for OUR server first}"
: "${LIVEKIT_API_KEY:?set LIVEKIT_API_KEY}"
: "${LIVEKIT_API_SECRET:?set LIVEKIT_API_SECRET}"
case "$LIVEKIT_URL" in
    *livekit.cloud*) echo "LIVEKIT_URL points at LiveKit Cloud. The trunk belongs on our own server."; exit 1 ;;
esac

TRUNK_JSON=$(mktemp)
trap 'rm -f "$TRUNK_JSON"' EXIT

cat > "$TRUNK_JSON" <<JSON
{
  "trunk": {
    "name": "Plivo — Super Karigar screening",
    "address": "$DOMAIN",
    "numbers": ["$NUMBER"],
    "transport": "$TRANSPORT"
  }
}
JSON

echo "Creating trunk on $LIVEKIT_URL"
echo "  carrier : $DOMAIN ($TRANSPORT)"
echo "  number  : $NUMBER"
echo

lk sip outbound create "$TRUNK_JSON" --auth-user "$SIP_USER" --auth-pass "$SIP_PASS"

echo
echo "Put the SIPTrunkID above in $ENV_FILE as LIVEKIT_SIP_TRUNK_ID, then redeploy."

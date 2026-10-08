#!/bin/sh
# Install this controller outside public_html. Never execute the fetched controller.
set -eu
umask 077
HOME_DIR=/home/v2quote.vietnamtraveladvisor.com.vn
CONTROL="$HOME_DIR/vta_private/fast-deploy"
SOURCE="$CONTROL/source"
PHP=/usr/local/lsws/lsphp83/bin/php
REPO=https://github.com/Vietnam202/Tour-Operator-.git
BRANCH=codex/Vietnam/rc6.2-testing

[ "$(id -un)" = vquot8508 ] || { echo 'REFUSED: staging owner required'; exit 1; }
[ "$(readlink -f "$HOME_DIR/public_html")" = "$HOME_DIR/public_html" ] || exit 1
[ "$(readlink -f "$CONTROL")" = "$CONTROL" ] || exit 1
[ "$(readlink -f "$0")" = "$CONTROL/deploy-staging.sh" ] || exit 1
[ -x "$PHP" ] || exit 1
exec 9>"$CONTROL/deploy.lock"
flock -n 9 || { echo 'BUSY: another staging deploy is running'; exit 1; }
export GIT_TERMINAL_PROMPT=0 GCM_INTERACTIVE=Never
export VTA_CONFIG_FILE="$HOME_DIR/vta_private/config.php"

case "${1:-}" in
  --check|--rollback|--resume)
    exec "$PHP" "$CONTROL/staging-release.php" "$@"
    ;;
  ''|--adopt) ;;
  *) echo 'REFUSED: unsupported deploy command'; exit 1 ;;
esac
[ ! -e "$CONTROL/HALTED" ] || { echo 'HALTED: review last-result.json before resuming'; exit 1; }
"$PHP" "$CONTROL/staging-release.php" --preflight
if [ ! -e "$SOURCE" ]; then
  mkdir -m 700 "$SOURCE"
  git -C "$SOURCE" init --quiet
  git -C "$SOURCE" remote add origin "$REPO"
fi
[ "$(readlink -f "$SOURCE")" = "$SOURCE" ] || exit 1
[ "$(git -C "$SOURCE" rev-parse --show-toplevel)" = "$SOURCE" ] || exit 1
[ "$(git -C "$SOURCE" remote get-url origin)" = "$REPO" ] || exit 1
[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=all)" ] || { echo 'REFUSED: private source cache is dirty'; exit 1; }
git -C "$SOURCE" config core.autocrlf false
mkdir -p "$CONTROL/disabled-hooks"
git -C "$SOURCE" config core.hooksPath "$CONTROL/disabled-hooks"
# A remote-tracking ref can rewind even without +; explicitly check ancestry.
PREVIOUS=$(git -C "$SOURCE" rev-parse --verify "refs/remotes/origin/$BRANCH^{commit}" 2>/dev/null || true)
git -C "$SOURCE" -c http.sslVerify=true -c http.lowSpeedLimit=1 -c http.lowSpeedTime=30 fetch --no-tags origin "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
TARGET=$(git -C "$SOURCE" rev-parse "refs/remotes/origin/$BRANCH^{commit}")
if [ -n "$PREVIOUS" ]; then
  git -C "$SOURCE" merge-base --is-ancestor "$PREVIOUS" "$TARGET" || { echo 'REFUSED: testing history was rewritten'; exit 1; }
fi
# Checkout resets only the isolated source cache, never the public application tree.
git -C "$SOURCE" checkout --quiet -B "$BRANCH" "$TARGET"
"$PHP" "$CONTROL/staging-release.php" "$@"

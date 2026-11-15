#!/usr/bin/env bash
# يركّب ffmpeg/ffprobe static كاملين (فيهم HEVC/H.265) في /usr/local/bin.
# السبب: ffmpeg-free بتاع Amazon Linux مفيهوش HEVC فبيرفض فيديوهات الآيفون.
# التشغيل:  sudo bash scripts/install-ffmpeg.sh
set -euo pipefail

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64)  PKG="ffmpeg-release-amd64-static.tar.xz" ;;
  aarch64) PKG="ffmpeg-release-arm64-static.tar.xz" ;;
  *) echo "Unsupported arch: $ARCH"; exit 1 ;;
esac

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
echo ">> downloading $PKG ..."
curl -fsSL "https://johnvansickle.com/ffmpeg/releases/$PKG" -o "$TMP/ff.tar.xz"
tar -xJf "$TMP/ff.tar.xz" -C "$TMP"
DIR="$(find "$TMP" -maxdepth 1 -type d -name 'ffmpeg-*-static' | head -1)"
install -m 0755 "$DIR/ffmpeg"  /usr/local/bin/ffmpeg
install -m 0755 "$DIR/ffprobe" /usr/local/bin/ffprobe

echo ">> installed:"; /usr/local/bin/ffmpeg -version | head -1
if /usr/local/bin/ffmpeg -hide_banner -decoders 2>/dev/null | grep -qiE '^ *V.* hevc'; then
  echo ">> HEVC decoder: OK"
else
  echo ">> WARNING: HEVC decoder still missing"; exit 1
fi

cat <<'MSG'

عدّل في .env:
  FFMPEG_PATH=/usr/local/bin/ffmpeg
  FFPROBE_PATH=/usr/local/bin/ffprobe
وبعدين:
  php artisan config:clear && php artisan config:cache
(ولو بتستخدم php-fpm / queue workers: restart ليهم)
MSG

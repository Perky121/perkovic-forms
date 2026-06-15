#!/bin/bash
# ============================================================
# Perković Forms — Release skript
# Upotreba: ./release.sh 1.5.9
# ============================================================

set -e

VERSION=$1

if [ -z "$VERSION" ]; then
  echo "❌ Upiši verziju: ./release.sh 1.5.9"
  exit 1
fi

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="perkovic-forms"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"

echo "📦 Kreiram ZIP za verziju $VERSION..."

# Provjeri da Version u PHP-u odgovara
PHP_VERSION=$(grep "Version:" "$PLUGIN_DIR/perkovic-forms.php" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
if [ "$PHP_VERSION" != "$VERSION" ]; then
  echo "⚠️  Upozorenje: Version u perkovic-forms.php je $PHP_VERSION, a ti radiš release $VERSION"
  echo "   Ažuriraj Version: i PF_VERSION u pluginu prije releasea!"
  read -p "   Nastavi svejedno? (y/n): " CONTINUE
  if [ "$CONTINUE" != "y" ]; then exit 1; fi
fi

# Kreiraj ZIP (isključi development fajlove)
cd "$PARENT_DIR"
zip -rq "$ZIP_NAME" "$PLUGIN_NAME" \
  --exclude "*.git*" \
  --exclude "*/.DS_Store" \
  --exclude "*/release.sh" \
  --exclude "*/.gitignore" \
  --exclude "*/node_modules/*" \
  --exclude "*/*.log"

echo "✅ Kreiran: $PARENT_DIR/$ZIP_NAME"
echo ""
echo "📤 Sljedeći koraci:"
echo "   1. Idi na: https://github.com/TVOJ_USERNAME/perkovic-forms/releases/new"
echo "   2. Tag:    $VERSION  (ili v$VERSION)"
echo "   3. Title:  Perković Forms $VERSION"
echo "   4. Priloži: $ZIP_NAME"
echo "   5. Publish release"
echo ""
echo "⚡ WordPress će automatski detektirati ažuriranje!"

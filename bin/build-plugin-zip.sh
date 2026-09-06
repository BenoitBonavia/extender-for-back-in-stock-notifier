#!/usr/bin/env bash
#
# Construit l'archive d'installation du plugin.
#
# L'archive est produite à partir d'une référence git (HEAD par défaut) via
# `git archive`, ce qui applique automatiquement les règles `export-ignore` du
# fichier .gitattributes. Les modifications non commitées sont donc ignorées :
# c'est volontaire, l'archive doit être reproductible depuis le dépôt.
#
# Usage :
#   bin/build-plugin-zip.sh [--ref <git-ref>] [--out <dossier>] [--expect <version>]
#
#   --ref     Référence git à archiver (défaut : HEAD).
#   --out     Dossier de sortie (défaut : dist/).
#   --expect  Version attendue ; échoue si elle diverge des en-têtes. Utilisé en
#             intégration continue pour comparer le tag git et l'en-tête Version.
#
set -euo pipefail

SLUG="extender-for-back-in-stock-notifier"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

REF="HEAD"
OUT_DIR="$ROOT/dist"
EXPECT=""

while [ $# -gt 0 ]; do
	case "$1" in
		--ref)    REF="$2";     shift 2 ;;
		--out)    OUT_DIR="$2"; shift 2 ;;
		--expect) EXPECT="$2";  shift 2 ;;
		-h|--help)
			sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*)
			echo "Option inconnue : $1" >&2
			exit 1
			;;
	esac
done

show_from_ref() {
	git -C "$ROOT" show "$REF:$1"
}

# ---------------------------------------------------------------------------
# Cohérence des numéros de version
#
# Le vérificateur de mises à jour lit l'en-tête « Version: » du fichier principal
# TEL QU'IL EST dans le tag distant, et non le nom du tag. Un tag v1.2.3 posé sur
# un fichier resté en 1.2.2 ne déclenche donc aucune mise à jour, sans erreur.
# ---------------------------------------------------------------------------

HEADER_VERSION="$(show_from_ref "$SLUG.php" \
	| grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' \
	| sed -E 's/.*Version:[[:space:]]*//' \
	| tr -d '[:space:]')"

CONST_VERSION="$(show_from_ref "$SLUG.php" \
	| grep -m1 "EBISN_VERSION" \
	| sed -E "s/.*'EBISN_VERSION',[[:space:]]*'([^']+)'.*/\1/")"

README_VERSION="$(show_from_ref readme.txt \
	| grep -m1 -E '^Stable tag:' \
	| sed -E 's/^Stable tag:[[:space:]]*//' \
	| tr -d '[:space:]')"

if [ -z "$HEADER_VERSION" ]; then
	echo "ERREUR : en-tête « Version: » introuvable dans $SLUG.php" >&2
	exit 1
fi

fail_mismatch() {
	echo "ERREUR : numéros de version incohérents." >&2
	echo "  en-tête Version:        $HEADER_VERSION" >&2
	echo "  constante EBISN_VERSION: $CONST_VERSION" >&2
	echo "  readme.txt Stable tag:  $README_VERSION" >&2
	[ -n "$EXPECT" ] && echo "  version attendue:       $EXPECT" >&2
	exit 1
}

[ "$HEADER_VERSION" = "$CONST_VERSION" ]  || fail_mismatch
[ "$HEADER_VERSION" = "$README_VERSION" ] || fail_mismatch

if [ -n "$EXPECT" ] && [ "$HEADER_VERSION" != "$EXPECT" ]; then
	fail_mismatch
fi

VERSION="$HEADER_VERSION"

# ---------------------------------------------------------------------------
# Construction
# ---------------------------------------------------------------------------

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

STAGE="$BUILD/$SLUG"
mkdir -p "$STAGE"

git -C "$ROOT" archive --format=tar "$REF" | tar -x -C "$STAGE"

# Résidus macOS éventuels.
find "$STAGE" \( -name '.DS_Store' -o -name '._*' \) -delete 2>/dev/null || true

mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"

# On compresse le DOSSIER depuis son parent : l'archive doit contenir un unique
# dossier racine nommé exactement comme le slug. Une archive « à plat » est
# rejetée à l'installation.
( cd "$BUILD" && zip -qrX "$ZIP" "$SLUG" )

# ---------------------------------------------------------------------------
# Vérifications de l'archive produite
# ---------------------------------------------------------------------------

if command -v unzip >/dev/null 2>&1; then
	# Le listing est capturé une fois, puis interrogé par « here-string ».
	# Enchaîner `unzip -Z1 | grep -q` échouerait systématiquement : grep sort au
	# premier résultat, unzip reçoit un SIGPIPE, et `set -o pipefail` transforme
	# cette terminaison normale en échec du pipeline.
	LISTING="$(unzip -Z1 "$ZIP")"

	ROOTS="$(cut -d/ -f1 <<< "$LISTING" | sort -u)"

	if [ "$ROOTS" != "$SLUG" ]; then
		echo "ERREUR : l'archive doit contenir un seul dossier racine « $SLUG »." >&2
		echo "Trouvé : $ROOTS" >&2
		exit 1
	fi

	for required in \
		"$SLUG/$SLUG.php" \
		"$SLUG/readme.txt" \
		"$SLUG/uninstall.php" \
		"$SLUG/src/Updater.php" \
		"$SLUG/src/Requirements.php" \
		"$SLUG/src/Integration/BackInStockNotifier.php" \
		"$SLUG/src/Admin/SettingsFields.php" \
		"$SLUG/src/Admin/SettingsPage.php" \
		"$SLUG/assets/css/admin.css" \
		"$SLUG/assets/js/admin.js" \
		"$SLUG/assets/css/unsubscribe.css" \
		"$SLUG/assets/js/unsubscribe.js" \
		"$SLUG/lib/plugin-update-checker/plugin-update-checker.php" \
		"$SLUG/lib/plugin-update-checker/vendor/PucReadmeParser.php"
	do
		if ! grep -qxF "$required" <<< "$LISTING"; then
			echo "ERREUR : fichier absent de l'archive : $required" >&2
			exit 1
		fi
	done

	# Les fichiers de développement ne doivent pas être livrés (export-ignore).
	for excluded in \
		"$SLUG/composer.json" \
		"$SLUG/phpcs.xml.dist" \
		"$SLUG/phpstan.neon.dist" \
		"$SLUG/README.md" \
		"$SLUG/.gitignore"
	do
		if grep -qxF "$excluded" <<< "$LISTING"; then
			echo "ERREUR : fichier de développement présent dans l'archive : $excluded" >&2
			exit 1
		fi
	done

	if grep -q "^$SLUG/\(\.github\|bin\)/" <<< "$LISTING"; then
		echo "ERREUR : .github/ ou bin/ présents dans l'archive." >&2
		exit 1
	fi
fi

echo "Version : $VERSION"
echo "Archive : $ZIP"
echo "Taille  : $(du -h "$ZIP" | cut -f1)"

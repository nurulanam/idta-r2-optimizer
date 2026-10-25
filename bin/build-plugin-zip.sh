#!/usr/bin/env bash
# ==============================================================================
# IDTA R2 Optimizer - Production Build & Packaging Script
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VERSION="1.0.0"
DIST_DIR="${PLUGIN_ROOT}/dist"
ZIP_NAME="idta-r2-optimizer-v${VERSION}.zip"
BUILD_TEMP="/tmp/idta-r2-build-$$-tmp"

echo "=========================================================="
echo " Building IDTA R2 Optimizer v${VERSION}"
echo " Root: ${PLUGIN_ROOT}"
echo "=========================================================="

# 1. Validate PHP syntax across all files
echo "1. Validating PHP syntax..."
for file in $(find "${PLUGIN_ROOT}" -name "*.php" ! -path "*/node_modules/*" ! -path "*/vendor/*"); do
	php -l "${file}" >/dev/null || {
		echo "PHP syntax error in: ${file}"
		exit 1
	}
done
echo "✓ All PHP files passed syntax linting."

# 2. Run Test Suite
echo "2. Executing Master Test Suite..."
if [ -f "${PLUGIN_ROOT}/tests/test-suite.php" ]; then
	php "${PLUGIN_ROOT}/tests/test-suite.php" >/dev/null || {
		echo "Master test suite failed!"
		exit 1
	}
	echo "✓ Master QA Test Suite passed (100%)."
fi

# 3. Minify Assets if minifiers available
echo "3. Compiling production minified assets..."
if [ -x "/usr/bin/terser" ]; then
	/usr/bin/terser "${PLUGIN_ROOT}/assets/js/admin.js" -c -m -o "${PLUGIN_ROOT}/assets/js/admin.min.js"
	echo "✓ Generated assets/js/admin.min.js"
fi

CSSO_CLI="/home/user/.npm/_npx/0f22d92d2e55ca56/node_modules/csso-cli/bin/csso"
if [ -f "${CSSO_CLI}" ]; then
	node "${CSSO_CLI}" "${PLUGIN_ROOT}/assets/css/admin.css" -o "${PLUGIN_ROOT}/assets/css/admin.min.css"
	echo "✓ Generated assets/css/admin.min.css"
fi

# 4. Prepare Staging Directory
echo "4. Assembling clean production payload..."
rm -rf "${BUILD_TEMP}"
mkdir -p "${BUILD_TEMP}/idta-r2-optimizer"
mkdir -p "${DIST_DIR}"

# Copy production files
cp "${PLUGIN_ROOT}/idta-r2-optimizer.php" "${BUILD_TEMP}/idta-r2-optimizer/"
cp "${PLUGIN_ROOT}/uninstall.php" "${BUILD_TEMP}/idta-r2-optimizer/"
cp "${PLUGIN_ROOT}/README.md" "${BUILD_TEMP}/idta-r2-optimizer/"
cp "${PLUGIN_ROOT}/roadmap.md" "${BUILD_TEMP}/idta-r2-optimizer/"
cp -r "${PLUGIN_ROOT}/includes" "${BUILD_TEMP}/idta-r2-optimizer/"
cp -r "${PLUGIN_ROOT}/templates" "${BUILD_TEMP}/idta-r2-optimizer/"
cp -r "${PLUGIN_ROOT}/assets" "${BUILD_TEMP}/idta-r2-optimizer/"

# 5. Create Distribution Zip Archive
echo "5. Generating production ZIP archive..."
cd "${BUILD_TEMP}"
rm -f "${DIST_DIR}/${ZIP_NAME}"
zip -q -r "${DIST_DIR}/${ZIP_NAME}" idta-r2-optimizer/

# Clean temporary build directory
rm -rf "${BUILD_TEMP}"

# Output details
ARCHIVE_SIZE=$(du -h "${DIST_DIR}/${ZIP_NAME}" | cut -f1)
echo "=========================================================="
echo " BUILD SUCCESSFUL!"
echo " Archive: ${DIST_DIR}/${ZIP_NAME}"
echo " Size:    ${ARCHIVE_SIZE}"
echo "=========================================================="


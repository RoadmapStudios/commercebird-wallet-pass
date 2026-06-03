#!/usr/bin/env sh
PLUGIN_SLUG="$(basename "$PWD")"
PROJECT_PATH=$(pwd)
BUILD_PATH="./build-zip"
DEST_PATH="${BUILD_PATH}/${PLUGIN_SLUG}"

# Function to display progress messages
progress_message() {
  local message="$1"
  local color_green="\033[32m"
  local color_reset="\033[0m"
  echo -e "[$(date +'%Y-%m-%d %H:%M:%S')] ${color_green}${message}${color_reset}"
}

# Abort on errors
set -e

# Prepare build directory
progress_message "Preparing build directory..."
rm -rf "$BUILD_PATH"
rm -rf "$PLUGIN_SLUG".zip
mkdir -p "$DEST_PATH"
mkdir -p "$BUILD_PATH"

echo "PROJECT_PATH: $PROJECT_PATH"
echo "BUILD_PATH: $BUILD_PATH"
echo "DEST_PATH: $DEST_PATH"

# cd "$PROJECT_PATH"
progress_message "DEBUG: Listing project path..."
cd "$PROJECT_PATH"
ls -l "$PROJECT_PATH"

# copy all files for production
progress_message "Copying files for production..."
rsync -av --exclude-from=".distignore" . "$DEST_PATH/"

# Install PHP dependencies
progress_message "Installing PHP dependencies..."
composer install --working-dir="$DEST_PATH" --no-dev --optimize-autoloader
rm "$DEST_PATH/composer.lock"

# Add index.php to every directory (excluding vendor, which is blocked via .htaccess)
progress_message "Adding index.php to every directory..."
find "$DEST_PATH" -type d -not -path "*/vendor/*" -exec sh -c "echo '<?php // silence' > {}/index.php" \;
echo 'Deny from all' > "$DEST_PATH/vendor/.htaccess"

# Completion message
progress_message "Build process completed successfully."
exit
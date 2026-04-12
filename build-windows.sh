#!/usr/bin/env sh
PLUGIN_SLUG="$(basename $PWD)"
PROJECT_PATH=$(pwd)
BUILD_PATH="./build-zip"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

export PATH="/c/msys64/usr/bin:$PATH"

# Function to display progress messages
progress_message() {
  local message="$1"

  # Define color codes
  local color_reset="\033[0m"
  local color_green="\033[32m"

  # Print the colorized message
  echo -e "[$(date +'%Y-%m-%d %H:%M:%S')] ${color_green}${message}${color_reset}"
}

# abort on errors
set -e

# prepare place for build.
progress_message "Preparing build directory..."
rm -rf "$BUILD_PATH"
rm -rf "$PLUGIN_SLUG".zip
mkdir -p "$DEST_PATH"

# copy all files for production
progress_message "Copying files for production..."
rsync -av --exclude-from=".distignore" . "$DEST_PATH/"

# Install PHP dependencies
progress_message "Installing PHP dependencies..."
composer install --working-dir="$DEST_PATH" --no-dev --optimize-autoloader
rm "$DEST_PATH/composer.lock"

## Create zip archive
progress_message "Creating zip archive..."
cd "$BUILD_PATH"
"C:\Program Files\WinRAR\WinRAR.exe" a "$PROJECT_PATH/$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"/*

# Completion message
progress_message "Build process completed successfully."
exit

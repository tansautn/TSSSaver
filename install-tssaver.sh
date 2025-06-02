#!/bin/bash
set -e

# --- 1. Dwl tsschecker ---
REPO="1Conan/tsschecker"
ZIP_NAME="tsschecker_linux_x86_64"
echo "Fetching latest tsschecker release..."
LATEST_URL=$(curl -s "https://api.github.com/repos/$REPO/releases/latest" \
  | jq -r ".assets[] | select(.name == \"$ZIP_NAME\") | .browser_download_url") || exit 1
echo "Downloading tsschecker from $LATEST_URL"
curl -L -o "/usr/local/bin/tssaver" "$LATEST_URL"  || exit 1
# --- 2. Dwn img4tool ---
REPO="tihmstar/img4tool"
ZIP_NAME="buildroot_ubuntu-latest.zip"
TMP_DIR="/tmp/img4tool"
DEST_DIR="/usr/local"
echo "Fetching latest img4tool release zip..."
LATEST_URL=$(curl -s "https://api.github.com/repos/$REPO/releases/latest" \
  | jq -r ".assets[] | select(.name == \"$ZIP_NAME\") | .browser_download_url") || exit 1
echo "Downloading img4tool from $LATEST_URL"
mkdir -p "$TMP_DIR"
curl -L -o "$TMP_DIR/$ZIP_NAME" "$LATEST_URL"  || exit 1

echo "Unzipping..."
unzip -o "$TMP_DIR/$ZIP_NAME" -d "$TMP_DIR" || exit 1

# Move img4tool to /usr/local (requires sudo)
echo "Installing img4tool to $DEST_DIR..."
cp -r "$TMP_DIR/buildroot_ubuntu-latest/usr/local/"* "$DEST_DIR"/ || exit 1
echo "chmoding ..."
DEST_DIR="/usr/local/bin"

# List of binaries
BINARIES=("img4tool" "lzfse" "tssaver")
for bin in "${BINARIES[@]}"; do
  TARGET="$DEST_DIR/$bin"
  if [ -f "$TARGET" ]; then
    chmod u+x "$TARGET" || exit 1
  else
    echo "Missing binary: $TARGET"
    exit 1
  fi
done

ENV_FILE=".env"
# Step 3: Load .env
if [ ! -f ".env" ]; then
  echo ".env file not found!"
  exit 1
fi
# --- 4. Import SQL ---
DB_NAME=$(grep DB_NAME "$ENV_FILE" | cut -d '=' -f2 | tr -d '"')
DB_USER=$(grep DB_USER "$ENV_FILE" | cut -d '=' -f2 | tr -d '"')
DB_PASS=$(grep DB_PASSWORD "$ENV_FILE" | cut -d '=' -f2 | tr -d '"')
SQL_FILE="devices.sql"
if [ -f "$SQL_FILE" ]; then
  # Create DB if not exists
  echo "Creating database $DB_NAME if not exists..."
  mysql -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;" || exit 1

  # Now import devices.sql
  echo "Importing devices.sql into database $DB_NAME..."
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < ./devices.sql || exit 1
else
  echo "ERROR: $SQL_FILE not found!"
  exit 1
fi

# --- 5. Add cron job ---
CRON_JOB="@daily php $CRON_FILE"
CRON_FILE="$(realpath cron.php)"
CRON_USER=$(grep WEBSERVER_USER "$ENV_FILE" | cut -d '=' -f2 | tr -d '"')
echo "Installing cronjob for $CRON_USER..."
( crontab -u "$CRON_USER" -l 2>/dev/null; echo "$CRON_JOB" ) | crontab -u "$CRON_USER" -

echo "✅ All done."

#!/bin/bash
# 410strategy.com — Deploy Script
# Usage: ./deploy.sh
# Deploys all site files from GitHub repo to Bluehost via cPanel API
# Run after: git push origin main (and Aaron approval)

set -e

BLUEHOST_USER="shopjac1"
BLUEHOST_HOST="box5476.bluehost.com"
BLUEHOST_TOKEN="K3II3ZYXJYH92ORN6V943HY98HVHCFTX"
REMOTE_DIR="/home4/shopjac1/public_html/410strategy"
LOCAL_DIR="$(dirname "$0")/410-website"

echo "=== 410 Strategy Deploy ==="
echo "Source: $LOCAL_DIR"
echo "Target: $REMOTE_DIR"
echo ""

deploy_file() {
    local filename="$1"
    local content
    content=$(cat "$LOCAL_DIR/$filename")
    
    response=$(python3 -c "
import urllib.parse, subprocess, json, sys
content = open('$LOCAL_DIR/$filename').read()
params = urllib.parse.urlencode({
    'cpanel_jsonapi_version': 2,
    'cpanel_jsonapi_module': 'Fileman',
    'cpanel_jsonapi_func': 'savefile',
    'dir': '$REMOTE_DIR',
    'filename': '$filename',
    'content': content
})
r = subprocess.run([
    'curl', '-s', '-X', 'POST',
    'https://$BLUEHOST_HOST:2083/json-api/cpanel',
    '-H', 'Authorization: cpanel $BLUEHOST_USER:$BLUEHOST_TOKEN',
    '--data', params
], capture_output=True, text=True, timeout=30)
d = json.loads(r.stdout)
ok = d.get('cpanelresult',{}).get('event',{}).get('result')
print('OK' if ok == 1 else 'FAIL: ' + str(d)[:100])
")
    echo "  $filename → $response"
}

# Deploy all site files
FILES=(
    "index.html"
    "contact.php"
    "intake.html"
    "intake.php"
    "intake-handler.php"
    "webhook.php"
    "privacy.html"
    "terms.html"
    "thank-you.html"
    "thank-you-complete.html"
    "style.css"
)

for f in "${FILES[@]}"; do
    if [ -f "$LOCAL_DIR/$f" ]; then
        deploy_file "$f"
    fi
done

# Deploy blog files if they exist
if [ -d "$LOCAL_DIR/blog" ]; then
    for f in "$LOCAL_DIR/blog"/*.html "$LOCAL_DIR/blog"/*.php 2>/dev/null; do
        [ -f "$f" ] && deploy_file "blog/$(basename $f)"
    done
fi

echo ""
echo "=== Deploy complete ==="
echo "Verify: https://410strategy.com/"
echo "Git commit: $(git rev-parse --short HEAD)"

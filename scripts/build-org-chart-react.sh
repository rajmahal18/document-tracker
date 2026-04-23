#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../frontend/org-chart-react"
npm install
npm run build
echo "React org chart build complete. Refresh public/org_chart.php in your browser."

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\frontend\org-chart-react')
npm install
npm run build
Write-Host 'React org chart build complete. Refresh public/org_chart.php in your browser.'

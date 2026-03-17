param()

$git = git rev-parse --show-toplevel 2>$null
if ($LASTEXITCODE -ne 0) {
  Write-Host 'Not a git repo.' -ForegroundColor Red
  exit 1
}

git config core.hooksPath .githooks
Write-Host 'Installed hooks to .githooks' -ForegroundColor Green

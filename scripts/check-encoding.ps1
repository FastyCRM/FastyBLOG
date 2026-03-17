param(
  [string]$Root = (Split-Path -Parent $PSScriptRoot)
)

$extensions = @('.php', '.js', '.css', '.md', '.sql', '.json', '.html')
$ignore = @(
  '\\.git\\',
  '\\node_modules\\',
  '\\vendor\\',
  '\\logs\\',
  '\\storage\\',
  '\\adm\\view\\assets\\img\\'
)

$utf8 = New-Object System.Text.UTF8Encoding($false, $true)
$bad = New-Object System.Collections.Generic.List[string]
$mojibakeRegex = '(?:\u0420|\u0421)[\u0400-\u045F-[\u0401\u0451\u0410-\u042F\u0430-\u044F]]'

$files = Get-ChildItem -Path $Root -File -Recurse | Where-Object {
  $extOk = $extensions -contains $_.Extension.ToLower()
  if (-not $extOk) { return $false }
  foreach ($pat in $ignore) {
    if ($_.FullName -like "*$pat*") { return $false }
  }
  return $true
}

foreach ($file in $files) {
  $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
  if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    $bad.Add("$($file.FullName) : BOM detected")
    continue
  }
  try {
    $text = $utf8.GetString($bytes)
  } catch {
    $bad.Add("$($file.FullName) : invalid UTF-8")
    continue
  }
  if ($text.IndexOf([char]0xFFFD) -ge 0) {
    $bad.Add("$($file.FullName) : contains U+FFFD replacement chars")
  }
  if ($text -match $mojibakeRegex) {
    $bad.Add("$($file.FullName) : looks like mojibake (CP1251/UTF-8 mix)")
  }

  # Heuristic: detect broken line endings (collapsed into one line).
  $lineCount = ($text -split "`r`n|`n|`r").Count
  $nameLower = $file.Name.ToLower()
  $isMinified = $nameLower -match '\.min\.'
  if (-not $isMinified -and $text.Length -gt 2000 -and $lineCount -le 1) {
    $bad.Add("$($file.FullName) : looks like line breaks collapsed")
  }
}

if ($bad.Count -gt 0) {
  Write-Host "Encoding check failed:`n" -ForegroundColor Red
  $bad | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
  exit 1
}

Write-Host "Encoding check OK." -ForegroundColor Green

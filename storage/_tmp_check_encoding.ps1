$files = Get-ChildItem -Recurse -File adm/modules/bot_adv_calendar
$utf8 = New-Object System.Text.UTF8Encoding($false,$true)
foreach ($f in $files) {
  $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
  $hasBom = $bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF
  $utf8Valid = $true
  try { [void]$utf8.GetString($bytes) } catch { $utf8Valid = $false }
  "{0}`tUTF8_VALID={1}`tUTF8_BOM={2}" -f $f.FullName.Replace((Get-Location).Path + '\\',''), $utf8Valid, $hasBom
}

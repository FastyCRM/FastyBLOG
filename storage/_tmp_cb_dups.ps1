$path = 'adm/modules/channel_bridge/assets/php/channel_bridge_async_lib.php'
$lines = Get-Content -Path $path
$start = -1
$sql = @()
$idx = 0
foreach ($line in $lines) {
  $idx++
  if ($line -match '\$pdo->prepare\("') {
    $start = $idx
    $sql = @($line)
    continue
  }
  if ($start -gt 0) {
    $sql += $line
    if ($line -match '"\)->execute') {
      $text = ($sql -join "`n")
      $matches = [regex]::Matches($text, ':[A-Za-z_][A-Za-z0-9_]*') | ForEach-Object { $_.Value }
      $dups = $matches | Group-Object | Where-Object { $_.Count -gt 1 }
      if ($dups) {
        Write-Output "STATEMENT starting line $start"
        $dups | ForEach-Object { Write-Output ("  DUP " + $_.Name + " x" + $_.Count) }
      }
      $start = -1
      $sql = @()
    }
  }
}

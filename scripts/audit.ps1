# scripts/audit.ps1
New-Item -ItemType Directory -Force -Path scripts\out | Out-Null

Get-ChildItem -Recurse -File | Select-Object FullName, Length |
  Export-Csv scripts\out\files_all.csv -NoTypeInformation

Get-ChildItem -Recurse -File | Get-FileHash | Group-Object Hash | Where-Object Count -gt 1 |
  ForEach-Object { $_.Group | Select-Object Path, Hash } |
  Export-Csv scripts\out\duplicates.csv -NoTypeInformation

Get-ChildItem -Recurse -File | Sort-Object Length -Descending |
  Select-Object FullName, Length -First 100 |
  Export-Csv scripts\out\top100_sizes.csv -NoTypeInformation

$all = Get-ChildItem -Recurse -File -Include *.php | % { $_.FullName }
$refs = Select-String -Path $all -Pattern "include|require|href=|window.location|fetch\(" -SimpleMatch | % { $_.Path } | Select-Object -Unique
$unused = $all | ? { $refs -notcontains $_ }
$unused | Set-Content scripts\out\maybe_unused_php.txt

Write-Host "Audit fertig. Berichte in scripts\out\"

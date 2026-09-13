$path = "C:\xampp\htdocs\pendenz.com\pages\benutzer.php"
$content = [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)

# Un-mangle common characters
$content = $content.Replace("Ã¼", "ü")
$content = $content.Replace("Ã¶", "ö")
$content = $content.Replace("Ã¤", "ä")
$content = $content.Replace("Ãœ", "Ü")
$content = $content.Replace("Ã–", "Ö")
$content = $content.Replace("Ã„", "Ä")
$content = $content.Replace("ÃŸ", "ß")
$content = $content.Replace("â€”", "—")
$content = $content.Replace("Â²", "²")
$content = $content.Replace("ðŸ‘¥", "👥")
$content = $content.Replace("ðŸ‘¤", "👤")
$content = $content.Replace("âœ¨", "✨")
$content = $content.Replace("ðŸ“", "📑")
$content = $content.Replace("ðŸ’", "💡")
$content = $content.Replace("ðŸ", "✨") # Fallback for some mangled emojis

$utf8NoBOM = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($path, $content, $utf8NoBOM)

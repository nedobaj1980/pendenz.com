<?php
$basePath = dirname(__DIR__);
$templatePath = $basePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'wohnungsabnahme_template.html';

echo "DIR: " . __DIR__ . "\n";
echo "BasePath: " . $basePath . "\n";
echo "TemplatePath: " . $templatePath . "\n";
echo "Exists: " . (file_exists($templatePath) ? 'YES' : 'NO') . "\n";

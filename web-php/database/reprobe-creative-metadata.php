<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\CampaignCreativeRepository;
use OcMaker\CreativeMediaProbe;

$repo = new CampaignCreativeRepository();
$probe = new CreativeMediaProbe();
$rows = $repo->listMissingMetadata();

if ($rows === []) {
    echo "Nenhum criativo pendente de metadados.\n";
    exit(0);
}

$updated = 0;
foreach ($rows as $row) {
    $path = (string) ($row['file_path'] ?? '');
    $mime = (string) ($row['mime_type'] ?? 'video/mp4');
    if ($path === '' || !is_file($path)) {
        fwrite(STDERR, "Arquivo ausente (#{$row['id']}): {$path}\n");
        continue;
    }

    $media = $probe->probe($path, $mime);
    $repo->updateMetadata((int) $row['id'], $media);
    $updated++;
    echo "#{$row['id']} {$path}\n";
    echo "  {$media['width']}x{$media['height']} · {$media['duration_seconds']}s · {$media['frame_rate']}fps\n";
}

echo "\nMetadados atualizados: {$updated}\n";

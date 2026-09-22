<?php

declare(strict_types=1);

namespace OcMaker;

final class CreativeMediaProbe
{
    /** @return array{width: ?int, height: ?int, duration_seconds: ?float, frame_rate: ?float} */
    public function probe(string $path, string $mime): array
    {
        if (!is_file($path)) {
            return $this->empty();
        }

        if (str_starts_with($mime, 'video/')) {
            $ffprobe = $this->probeFfprobe($path);
            if ($ffprobe !== null) {
                return $ffprobe;
            }

            if ($mime === 'video/mp4' || str_ends_with(strtolower($path), '.mp4')) {
                return $this->probeMp4($path);
            }
        }

        if (str_starts_with($mime, 'image/') && function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info)) {
                return [
                    'width' => (int) ($info[0] ?? 0) ?: null,
                    'height' => (int) ($info[1] ?? 0) ?: null,
                    'duration_seconds' => null,
                    'frame_rate' => null,
                ];
            }
        }

        return $this->empty();
    }

    /** @return array{width: ?int, height: ?int, duration_seconds: ?float, frame_rate: ?float} */
    private function probeFfprobe(string $path): ?array
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $binary = $this->findFfprobeBinary();
        if ($binary === null) {
            return null;
        }

        $cmd = escapeshellarg($binary)
            . ' -v quiet -print_format json -show_streams -show_format '
            . escapeshellarg($path);
        $output = @shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($output, true);
        if (!is_array($json)) {
            return null;
        }

        $video = null;
        foreach ($json['streams'] ?? [] as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
                $video = $stream;
                break;
            }
        }

        if ($video === null) {
            return null;
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        $duration = $this->toFloat($json['format']['duration'] ?? $video['duration'] ?? null);
        $fps = $this->parseFrameRate((string) ($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? ''));

        return [
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'duration_seconds' => $duration,
            'frame_rate' => $fps,
        ];
    }

    /** @return array{width: ?int, height: ?int, duration_seconds: ?float, frame_rate: ?float} */
    private function probeMp4(string $path): array
    {
        $result = $this->empty();
        $size = filesize($path);
        if ($size === false || $size < 32) {
            return $result;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return $result;
        }

        try {
            $moov = $this->findAtom($fh, 0, (int) $size, 'moov');
            if ($moov === null) {
                return $result;
            }

            $mvhd = $this->findAtomInRange($fh, $moov['start'], $moov['end'], 'mvhd');
            if ($mvhd !== null) {
                $mv = $this->readMvhd($fh, $mvhd['start'], $mvhd['end'] - $mvhd['start']);
                if ($mv['duration_seconds'] !== null) {
                    $result['duration_seconds'] = $mv['duration_seconds'];
                }
            }

            foreach ($this->listAtomsInRange($fh, $moov['start'], $moov['end'], 'trak') as $trak) {
                if (!$this->isVideoTrack($fh, $trak['start'], $trak['end'])) {
                    continue;
                }

                $tkhd = $this->findAtomInRange($fh, $trak['start'], $trak['end'], 'tkhd');
                if ($tkhd !== null) {
                    $dims = $this->readTkhdDimensions($fh, $tkhd['start'], $tkhd['end'] - $tkhd['start']);
                    if ($dims['width'] !== null) {
                        $result['width'] = $dims['width'];
                    }
                    if ($dims['height'] !== null) {
                        $result['height'] = $dims['height'];
                    }
                }

                if ($result['width'] === null || $result['height'] === null) {
                    $stsdDims = $this->readStsdDimensions($fh, $trak['start'], $trak['end']);
                    if ($result['width'] === null && $stsdDims['width'] !== null) {
                        $result['width'] = $stsdDims['width'];
                    }
                    if ($result['height'] === null && $stsdDims['height'] !== null) {
                        $result['height'] = $stsdDims['height'];
                    }
                }

                $fps = $this->readVideoFrameRate($fh, $trak['start'], $trak['end'], $result['duration_seconds']);
                if ($fps !== null) {
                    $result['frame_rate'] = $fps;
                }

                break;
            }
        } finally {
            fclose($fh);
        }

        return $result;
    }

    private function findFfprobeBinary(): ?string
    {
        foreach (['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        if (!function_exists('shell_exec')) {
            return null;
        }

        $which = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        if ($which !== '' && @is_executable($which)) {
            return $which;
        }

        return null;
    }

    /** @return array{start: int, end: int, size: int}|null */
    private function findAtom($fh, int $from, int $to, string $type): ?array
    {
        return $this->findAtomInRange($fh, $from, $to, $type);
    }

    /** @return array{start: int, end: int, size: int}|null */
    private function findAtomInRange($fh, int $from, int $to, string $type): ?array
    {
        $offset = $from;
        while ($offset + 8 <= $to) {
            $header = $this->readBytes($fh, $offset, 8);
            if ($header === null) {
                break;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $atomType = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $ext = $this->readBytes($fh, $offset + 8, 8);
                if ($ext === null) {
                    break;
                }
                $size = unpack('J', $ext)[1];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $to - $offset;
            }
            if ($size < $headerSize) {
                break;
            }

            $contentStart = $offset + $headerSize;
            $end = $offset + $size;
            if ($atomType === $type) {
                return ['start' => $contentStart, 'end' => $end, 'size' => $size];
            }

            if (in_array($atomType, ['moov', 'trak', 'mdia', 'minf', 'stbl'], true)) {
                $found = $this->findAtomInRange($fh, $contentStart, $end, $type);
                if ($found !== null) {
                    return $found;
                }
            }

            $offset = $end;
        }

        return null;
    }

    /** @return list<array{start: int, end: int, size: int}> */
    private function listAtomsInRange($fh, int $from, int $to, string $type): array
    {
        $matches = [];
        $offset = $from;
        while ($offset + 8 <= $to) {
            $header = $this->readBytes($fh, $offset, 8);
            if ($header === null) {
                break;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $atomType = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $ext = $this->readBytes($fh, $offset + 8, 8);
                if ($ext === null) {
                    break;
                }
                $size = unpack('J', $ext)[1];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $to - $offset;
            }
            if ($size < $headerSize) {
                break;
            }

            $contentStart = $offset + $headerSize;
            $end = $offset + $size;
            if ($atomType === $type) {
                $matches[] = ['start' => $contentStart, 'end' => $end, 'size' => $size];
            } elseif (in_array($atomType, ['moov', 'trak', 'mdia', 'minf', 'stbl'], true)) {
                array_push($matches, ...$this->listAtomsInRange($fh, $contentStart, $end, $type));
            }

            $offset = $end;
        }

        return $matches;
    }

    private function isVideoTrack($fh, int $from, int $to): bool
    {
        $mdia = $this->findAtomInRange($fh, $from, $to, 'mdia');
        if ($mdia === null) {
            return false;
        }
        $hdlr = $this->findAtomInRange($fh, $mdia['start'], $mdia['end'], 'hdlr');
        if ($hdlr === null) {
            return false;
        }
        $payload = $this->readBytes($fh, $hdlr['start'], min(20, $hdlr['end'] - $hdlr['start']));
        if ($payload === null || strlen($payload) < 12) {
            return false;
        }

        return substr($payload, 8, 4) === 'vide';
    }

    /** @return array{duration_seconds: ?float} */
    private function readMvhd($fh, int $contentStart, int $contentLength): array
    {
        $content = $this->readBytes($fh, $contentStart, min(64, $contentLength));
        if ($content === null || strlen($content) < 20) {
            return ['duration_seconds' => null];
        }

        $version = ord($content[0]);
        if ($version === 0) {
            $timescale = unpack('N', substr($content, 12, 4))[1];
            $duration = unpack('N', substr($content, 16, 4))[1];
        } else {
            if (strlen($content) < 32) {
                return ['duration_seconds' => null];
            }
            $timescale = unpack('N', substr($content, 20, 4))[1];
            $duration = unpack('J', substr($content, 24, 8))[1];
        }

        if ($timescale <= 0 || $duration <= 0) {
            return ['duration_seconds' => null];
        }

        return ['duration_seconds' => round($duration / $timescale, 3)];
    }

    /** @return array{width: ?int, height: ?int} */
    private function readTkhdDimensions($fh, int $start, int $contentLength): array
    {
        $content = $this->readBytes($fh, $start, min(96, max(0, $contentLength)));
        if ($content === null) {
            return ['width' => null, 'height' => null];
        }

        $version = ord($content[0]);
        $offset = $version === 0 ? 64 : 72;
        if (strlen($content) < $offset + 8) {
            return ['width' => null, 'height' => null];
        }

        $width = unpack('N', substr($content, $offset, 4))[1] / 65536;
        $height = unpack('N', substr($content, $offset + 4, 4))[1] / 65536;

        return [
            'width' => $width > 0 ? (int) round($width) : null,
            'height' => $height > 0 ? (int) round($height) : null,
        ];
    }

    /** @return array{width: ?int, height: ?int} */
    private function readStsdDimensions($fh, int $trakStart, int $trakEnd): array
    {
        $mdia = $this->findAtomInRange($fh, $trakStart, $trakEnd, 'mdia');
        if ($mdia === null) {
            return ['width' => null, 'height' => null];
        }
        $minf = $this->findAtomInRange($fh, $mdia['start'], $mdia['end'], 'minf');
        if ($minf === null) {
            return ['width' => null, 'height' => null];
        }
        $stbl = $this->findAtomInRange($fh, $minf['start'], $minf['end'], 'stbl');
        if ($stbl === null) {
            return ['width' => null, 'height' => null];
        }
        $stsd = $this->findAtomInRange($fh, $stbl['start'], $stbl['end'], 'stsd');
        if ($stsd === null) {
            return ['width' => null, 'height' => null];
        }

        $content = $this->readBytes($fh, $stsd['start'], min(96, $stsd['end'] - $stsd['start']));
        if ($content === null || strlen($content) < 40) {
            return ['width' => null, 'height' => null];
        }

        // VisualSampleEntry (avc1): width/height at bytes 40/42 from stsd content start.
        $width = unpack('n', substr($content, 40, 2))[1];
        $height = unpack('n', substr($content, 42, 2))[1];

        return [
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
        ];
    }

    private function readVideoFrameRate($fh, int $trakStart, int $trakEnd, ?float $durationSeconds): ?float
    {
        $mdia = $this->findAtomInRange($fh, $trakStart, $trakEnd, 'mdia');
        if ($mdia === null) {
            return null;
        }
        $minf = $this->findAtomInRange($fh, $mdia['start'], $mdia['end'], 'minf');
        if ($minf === null) {
            return null;
        }
        $stbl = $this->findAtomInRange($fh, $minf['start'], $minf['end'], 'stbl');
        if ($stbl === null) {
            return null;
        }

        if ($durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }

        $stsz = $this->findAtomInRange($fh, $stbl['start'], $stbl['end'], 'stsz');
        if ($stsz === null) {
            return null;
        }

        $content = $this->readBytes($fh, $stsz['start'], min(16, $stsz['end'] - $stsz['start']));
        if ($content === null || strlen($content) < 12) {
            return null;
        }

        $sampleCount = unpack('N', substr($content, 8, 4))[1];
        if ($sampleCount <= 0) {
            return null;
        }

        return round($sampleCount / $durationSeconds, 2);
    }

    private function parseFrameRate(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || $value === '0/0') {
            return null;
        }
        if (str_contains($value, '/')) {
            [$num, $den] = array_map('floatval', explode('/', $value, 2));
            if ($den <= 0) {
                return null;
            }

            return round($num / $den, 2);
        }

        $fps = (float) $value;

        return $fps > 0 ? round($fps, 2) : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = (float) $value;

        return $num > 0 ? round($num, 3) : null;
    }

    private function readBytes($fh, int $offset, int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }
        if (fseek($fh, $offset) !== 0) {
            return null;
        }
        $data = fread($fh, $length);

        return $data === false ? null : $data;
    }

    /** @return array{width: ?int, height: ?int, duration_seconds: ?float, frame_rate: ?float} */
    private function empty(): array
    {
        return [
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
            'frame_rate' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace OcMaker;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class DealReportImportService
{
    public function __construct(
        private readonly CampaignDealDailyReportRepository $reports = new CampaignDealDailyReportRepository(),
    ) {
    }

    /**
     * @param list<string> $knownScreenCodes
     * @param array<string, array{resolution: string, screen_code: string}> $deviceAliases
     * @return array{
     *   inserted: int, updated: int, skipped: int, ignored: int, unmatched: int,
     *   errors: list<string>, platform: string,
     *   pending_unmatched: list<array{device_name: string, row_count: int, cleaned_name: string}>
     * }
     */
    public function importForDeal(
        int $dealDbId,
        string $expectedDealId,
        string $filePath,
        string $originalName,
        array $knownScreenCodes = [],
        ?float $dealCpm = null,
        array $deviceAliases = [],
    ): array {
        $parsed = $this->parseFile($filePath, $originalName);
        $rows = $parsed['rows'];
        $platform = $parsed['platform'];
        if ($rows === []) {
            throw new \InvalidArgumentException('Nenhuma linha válida encontrada no arquivo.');
        }

        $result = [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'ignored' => 0,
            'unmatched' => 0,
            'errors' => [],
            'platform' => $parsed['platform'],
            'pending_unmatched' => [],
        ];
        /** @var array<string, array{device_name: string, row_count: int, cleaned_name: string}> $pendingByDevice */
        $pendingByDevice = [];

        foreach ($rows as $row) {
            $line = (int) ($row['_line'] ?? 0);
            unset($row['_line']);

            $rowDealId = trim((string) ($row['deal_id'] ?? ''));
            if ($rowDealId !== '' && strcasecmp($rowDealId, $expectedDealId) !== 0) {
                $result['ignored']++;
                continue;
            }

            if ($platform === 'admooh') {
                $deviceName = trim((string) ($row['device_name'] ?? ''));
                $matchedCode = $this->resolveAdmoohScreenCode($deviceName, $knownScreenCodes, $deviceAliases);
                if ($matchedCode === null) {
                    $result['unmatched']++;
                    $cleaned = AdmoohDeviceMatcher::cleanDeviceName($deviceName);
                    if (!isset($pendingByDevice[$deviceName])) {
                        $pendingByDevice[$deviceName] = [
                            'device_name' => $deviceName,
                            'row_count' => 0,
                            'cleaned_name' => $cleaned,
                        ];
                    }
                    $pendingByDevice[$deviceName]['row_count']++;
                    continue;
                }
                $row['screen_code'] = $matchedCode;
            }

            $reportDate = $this->parseDate($row['report_date'] ?? null, $platform);
            if ($reportDate === null) {
                $result['errors'][] = "Linha {$line}: data inválida.";
                continue;
            }

            $consumo = $this->parseNumber($row['consumo'] ?? null);
            $cpmAplicado = $this->parseNumber($row['cpm_aplicado'] ?? null);
            $impactos = $this->parseInteger($row['impactos'] ?? null);
            if ($platform === 'admooh' && $consumo === null && $dealCpm !== null && $dealCpm > 0 && $impactos !== null) {
                $cpmAplicado = $dealCpm;
                $consumo = round($impactos * $dealCpm / 1000, 2);
            }

            try {
                $action = $this->reports->upsert($dealDbId, [
                    'report_date' => $reportDate,
                    'screen_code' => trim((string) ($row['screen_code'] ?? '')),
                    'network' => trim((string) ($row['network'] ?? '')),
                    'requisicoes' => $this->parseInteger($row['requisicoes'] ?? null),
                    'impressoes' => $this->parseInteger($row['impressoes'] ?? null),
                    'impactos' => $impactos,
                    'moeda' => $this->parseCurrency($row['consumo'] ?? null, $row['moeda'] ?? 'BRL'),
                    'cpm_aplicado' => $cpmAplicado,
                    'consumo' => $consumo,
                ]);
                $result[$action]++;
            } catch (\Throwable $e) {
                $result['errors'][] = "Linha {$line}: " . $e->getMessage();
            }
        }

        $result['pending_unmatched'] = array_values($pendingByDevice);

        if ($result['inserted'] + $result['updated'] + $result['skipped'] === 0
            && $result['pending_unmatched'] === []
            && $result['errors'] === []) {
            throw new \InvalidArgumentException('Nenhuma linha corresponde ao Deal ' . $expectedDealId . '.');
        }

        return $result;
    }

    /**
     * @param list<string> $knownScreenCodes
     * @param array<string, array{resolution: string, screen_code: string}> $deviceAliases
     */
    private function resolveAdmoohScreenCode(string $deviceName, array $knownScreenCodes, array $deviceAliases): ?string
    {
        if ($deviceName === '') {
            return null;
        }

        if (isset($deviceAliases[$deviceName]['screen_code'])) {
            return (string) $deviceAliases[$deviceName]['screen_code'];
        }

        return AdmoohDeviceMatcher::match($deviceName, $knownScreenCodes);
    }

    /** @return array{platform: string, rows: list<array<string, mixed>>} */
    private function parseFile(string $filePath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Formato não suportado. Use CSV, XLS ou XLSX.');
        }

        $matrix = $ext === 'csv' ? $this->loadCsvMatrix($filePath) : $this->loadExcelMatrix($filePath);
        if ($matrix === []) {
            return ['platform' => 'generic', 'rows' => []];
        }

        $headerRowIndex = $this->findHeaderRowIndex($matrix);
        $headers = array_map([$this, 'normalizeHeader'], array_map('strval', $matrix[$headerRowIndex]));
        $platform = $this->detectPlatform($headers);
        $columnMap = $this->columnMapForPlatform($platform, $headers);
        if (!isset($columnMap['report_date'])) {
            throw new \InvalidArgumentException('Coluna de data não encontrada (ex.: Date, Data).');
        }

        $rows = [];
        for ($i = $headerRowIndex + 1, $n = count($matrix); $i < $n; $i++) {
            $line = $matrix[$i];
            if ($this->isEmptyRow($line)) {
                continue;
            }

            $row = ['_line' => $i + 1];
            foreach ($columnMap as $field => $colIndex) {
                $row[$field] = $line[$colIndex] ?? null;
            }

            if ($this->isSkippableRow($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return ['platform' => $platform, 'rows' => $rows];
    }

    /** @return list<list<mixed>> */
    private function loadCsvMatrix(string $filePath): array
    {
        $sample = file_get_contents($filePath, false, null, 0, 8192) ?: '';
        $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';

        $reader = new CsvReader();
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);

        return $reader->load($filePath)->getActiveSheet()->toArray(null, true, true, false);
    }

    /** @return list<list<mixed>> */
    private function loadExcelMatrix(string $filePath): array
    {
        return IOFactory::load($filePath)->getActiveSheet()->toArray(null, true, true, false);
    }

    /** @param list<list<mixed>> $matrix */
    private function findHeaderRowIndex(array $matrix): int
    {
        foreach ($matrix as $index => $row) {
            $normalized = array_map([$this, 'normalizeHeader'], array_map('strval', $row));
            if (in_array('date', $normalized, true) || in_array('data', $normalized, true)) {
                $hasDeal = in_array('deal id', $normalized, true)
                    || in_array('deal_id', $normalized, true)
                    || in_array('screen id', $normalized, true)
                    || in_array('screen_id', $normalized, true)
                    || in_array('device name', $normalized, true)
                    || in_array('paid impressions', $normalized, true)
                    || in_array('requested ads', $normalized, true)
                    || in_array('billable ad plays', $normalized, true)
                    || in_array('requested bids', $normalized, true);
                if ($hasDeal) {
                    return $index;
                }
            }
        }

        return 0;
    }

    /** @param list<string> $headers */
    private function detectPlatform(array $headers): string
    {
        if (in_array('paid impressions', $headers, true) && in_array('ad spots', $headers, true)) {
            return 'magnite';
        }
        if (in_array('screen id', $headers, true) || in_array('screen_id', $headers, true)) {
            return 'hivestack';
        }
        if (in_array('requested ads', $headers, true) && in_array('generated revenue', $headers, true)) {
            return 'outcon';
        }
        if (in_array('billable ad plays', $headers, true) && in_array('billable impressions', $headers, true)) {
            return 'adsmovil';
        }
        if (in_array('device name', $headers, true)
            && in_array('requested bids', $headers, true)
            && in_array('executed impacts', $headers, true)) {
            return 'admooh';
        }

        return 'generic';
    }

    /** @param list<string> $headers @return array<string, int> */
    private function columnMapForPlatform(string $platform, array $headers): array
    {
        $maps = [
            'magnite' => [
                'report_date' => ['date'],
                'deal_id' => ['deal id'],
                'requisicoes' => ['bid requests'],
                'impressoes' => ['ad spots'],
                'impactos' => ['paid impressions'],
                'consumo' => ['publisher gross revenue'],
            ],
            'hivestack' => [
                'report_date' => ['date'],
                'deal_id' => ['deal id', 'deal_id'],
                'network' => ['network'],
                'screen_code' => ['screen id', 'screen_id'],
                'impressoes' => ['plays'],
                'consumo' => ['spend'],
                'impactos' => ['impressions'],
            ],
            'outcon' => [
                'report_date' => ['date'],
                'deal_id' => ['deal id'],
                'requisicoes' => ['requested ads'],
                'impressoes' => ['displayed ads'],
                'impactos' => ['impressions'],
                'moeda' => ['currency'],
                'consumo' => ['generated revenue'],
            ],
            'adsmovil' => [
                'report_date' => ['date'],
                'deal_id' => ['deal id'],
                'requisicoes' => ['bid request'],
                'impressoes' => ['billable ad plays'],
                'impactos' => ['billable impressions'],
                'consumo' => ['revenue'],
            ],
            'admooh' => [
                'report_date' => ['date'],
                'device_name' => ['device name'],
                'requisicoes' => ['requested bids'],
                'impressoes' => ['executed bids'],
                'impactos' => ['executed impacts'],
            ],
            'generic' => [
                'report_date' => ['data', 'date', 'report date', 'dia'],
                'deal_id' => ['deal id', 'deal_id', 'id deal'],
                'requisicoes' => ['requisicoes', 'requisicoes', 'requests', 'bid requests', 'requested ads'],
                'impressoes' => ['impressoes', 'impressoes', 'ad spots', 'displayed ads', 'billable ad plays', 'plays'],
                'impactos' => ['impactos', 'impacto', 'paid impressions', 'billable impressions'],
                'moeda' => ['moeda', 'currency'],
                'cpm_aplicado' => ['cpm aplicado', 'cpm_aplicado', 'cpm'],
                'consumo' => ['consumo', 'spend', 'gasto', 'publisher gross revenue', 'generated revenue', 'revenue'],
                'screen_code' => ['screen id', 'screen_id', 'screen code', 'codigo tela', 'codigo', 'tela'],
                'network' => ['network', 'rede', 'publisher'],
            ],
        ];

        $aliases = $maps[$platform] ?? $maps['generic'];
        $map = [];
        foreach ($headers as $index => $header) {
            foreach ($aliases as $field => $options) {
                if (in_array($header, $options, true) && !isset($map[$field])) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    private function isSkippableRow(array $row): bool
    {
        $dateVal = trim((string) ($row['report_date'] ?? ''));
        if ($dateVal === '') {
            return true;
        }

        $lower = mb_strtolower($dateVal);

        return $lower === 'total' || str_contains($lower, 'total');
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value, " \t\n\r\0\x0B\""));
        $value = str_replace(['_', '-'], ' ', $value);
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function parseDate(mixed $value, string $platform = 'generic'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $value);

                return $dt->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $text);

            return $dt instanceof \DateTimeImmutable ? $dt->format('Y-m-d') : null;
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $text, $parts)) {
            $first = (int) $parts[1];
            $second = (int) $parts[2];
            $year = $parts[3];
            $preferUs = in_array($platform, ['hivestack', 'outcon', 'magnite', 'adsmovil'], true);

            if ($first > 12 && $second <= 12) {
                $dt = \DateTimeImmutable::createFromFormat('j/n/Y', "{$first}/{$second}/{$year}");
            } elseif ($second > 12 && $first <= 12) {
                $dt = \DateTimeImmutable::createFromFormat('n/j/Y', "{$first}/{$second}/{$year}");
            } elseif ($preferUs) {
                $dt = \DateTimeImmutable::createFromFormat('n/j/Y', "{$first}/{$second}/{$year}");
            } else {
                $dt = \DateTimeImmutable::createFromFormat('j/n/Y', "{$first}/{$second}/{$year}");
                if (!$dt instanceof \DateTimeImmutable) {
                    $dt = \DateTimeImmutable::createFromFormat('n/j/Y', "{$first}/{$second}/{$year}");
                }
            }

            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        foreach (['d/m/Y', 'd-m-Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $text);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($text);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function parseInteger(mixed $value): ?int
    {
        $number = $this->parseNumber($value);

        return $number === null ? null : (int) round($number);
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '' || strcasecmp($text, 'total') === 0) {
            return null;
        }

        if (preg_match('/^[\d,.-]+(?:\s*(BRL|USD|EUR))?$/i', $text, $simple)) {
            $numeric = $this->normalizeNumericString($simple[0]);

            return $numeric === null ? null : (float) $numeric;
        }

        if (preg_match('/([\d,.-]+)\s*(BRL|USD|EUR)\b/i', $text, $withCurrency)) {
            $numeric = $this->normalizeNumericString($withCurrency[1]);

            return $numeric === null ? null : (float) $numeric;
        }

        $stripped = preg_replace('/[^\d,.\-]/', '', $text) ?? '';
        if ($stripped === '' || $stripped === '-') {
            return null;
        }

        $numeric = $this->normalizeNumericString($stripped);

        return $numeric === null ? null : (float) $numeric;
    }

    private function normalizeNumericString(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $text)) {
            return str_replace(',', '', $text);
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $text)) {
            $text = str_replace('.', '', $text);

            return str_replace(',', '.', $text);
        }
        if (substr_count($text, ',') === 1 && substr_count($text, '.') === 0) {
            return str_replace(',', '.', $text);
        }

        return str_replace(',', '', $text);
    }

    private function parseCurrency(mixed $consumoValue, mixed $explicit): string
    {
        if (is_string($explicit) && preg_match('/\b(BRL|USD|EUR)\b/i', $explicit, $match)) {
            return strtoupper($match[1]);
        }
        if (is_string($consumoValue) && preg_match('/\b(BRL|USD|EUR)\b/i', $consumoValue, $match)) {
            return strtoupper($match[1]);
        }
        if (is_string($consumoValue) && str_contains($consumoValue, 'R$')) {
            return 'BRL';
        }
        if (is_string($consumoValue) && str_contains($consumoValue, '$')) {
            return 'USD';
        }

        $fallback = trim((string) $explicit);

        return $fallback !== '' ? strtoupper($fallback) : 'BRL';
    }

    /** @param list<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}

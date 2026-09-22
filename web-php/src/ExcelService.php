<?php

declare(strict_types=1);

namespace OcMaker;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExcelService
{
    /** @var array<string, \PhpOffice\PhpSpreadsheet\Spreadsheet> */
    private static array $spreadsheetCache = [];

    /** Nomes aceitos para a aba de inventário (rede em INVENTARIO!J:J). */
    private const INVENTORY_SHEET_NAMES = ['INVENTARIO', 'INVENTÁRIO'];

    private const COL = [
        'veiculo' => 'A',
        'codigo' => 'B',
        'denominacao' => 'C',
        'faces' => 'D',
        'classe_social' => 'E',
        'regiao' => 'F',
        'estado' => 'G',
        'cidade' => 'H',
        'segmento' => 'I',
        'rede' => 'J',
        'dias' => 'K',
        'publico' => 'M',
        'impactos' => 'O',
        'insercoes' => 'P',
        'desconto' => 'S',
        'bruto' => 'U',
        'liquido' => 'V',
        'cpm' => 'W',
        'ads_id' => 'AK',
        'agencia' => 'AL',
        'anunciante' => 'AM',
        'planejador' => 'AN',
        'campanha' => 'AO',
        'inicio' => 'AQ',
        'termino' => 'AR',
    ];

    private const FORMAT_ERROR =
        'Não foi possível ler os dados da planilha. Verifique se o arquivo é a planilha original '
        . 'exportada do Invian (aba INVENTARIO), sem colunas adicionadas, removidas ou deslocadas. '
        . 'Se necessário, baixe novamente no Invian e reenvie o arquivo correto.';

    /** @return list<array<string, string>> */
    public function listCampaigns(string $filePath): array
    {
        $sheet = $this->inventorySheet($filePath);
        $this->assertInventoryFormat($sheet);
        $campaigns = [];
        foreach ($this->rowsWithValues($sheet, self::COL['campanha']) as $row) {
            $adsId = $this->text($sheet, $row, self::COL['ads_id']);
            $campanha = $this->text($sheet, $row, self::COL['campanha']);
            if ($this->isValidAdsId($adsId) && $campanha !== '' && !$this->isHeaderMetadata($adsId, $campanha)) {
                $campaigns[] = [
                    'ads_id' => $adsId,
                    'campanha' => $campanha,
                    'anunciante' => $this->text($sheet, $row, self::COL['anunciante']),
                    'agencia' => $this->text($sheet, $row, self::COL['agencia']),
                    'row' => (string) $row,
                ];
            }
        }
        if ($campaigns === []) {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }

        return $campaigns;
    }

    /** @return array<string, mixed> */
    public function loadCampaign(string $filePath, ?string $adsId = null): array
    {
        $spreadsheet = $this->loadSpreadsheet($filePath);
        $sheet = $this->resolveInventorySheet($spreadsheet);
        $this->assertInventoryFormat($sheet);
        $metadataRow = $this->findMetadataRow($sheet, $adsId);
        $campaignAdsId = $this->text($sheet, $metadataRow, self::COL['ads_id']);
        if (!$this->isValidAdsId($campaignAdsId)) {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }

        $data = [
            'source_sheet' => $sheet->getTitle(),
            'source_rede_column' => self::COL['rede'],
            'ads_id' => $campaignAdsId,
            'agencia' => $this->text($sheet, $metadataRow, self::COL['agencia']),
            'anunciante' => $this->text($sheet, $metadataRow, self::COL['anunciante']),
            'planejador' => $this->text($sheet, $metadataRow, self::COL['planejador']),
            'campanha' => $this->text($sheet, $metadataRow, self::COL['campanha']),
            'inicio' => $this->dateValue($sheet, $metadataRow, self::COL['inicio']),
            'termino' => $this->dateValue($sheet, $metadataRow, self::COL['termino']),
            'inventory' => [],
            'observacoes' => [],
        ];

        $lastRede = '';
        $highestRow = $this->inventoryHighestRow($sheet);
        for ($row = $metadataRow; $row <= $highestRow; $row++) {
            if ($this->isNextCampaignMetadataRow($sheet, $row, $metadataRow, $campaignAdsId)) {
                break;
            }

            $codigo = $this->text($sheet, $row, self::COL['codigo']);
            $rede = $this->text($sheet, $row, self::COL['rede']);

            if ($codigo === '' && $rede !== '') {
                if (!$this->isGroupHeaderRede($rede) && !$this->isColumnHeaderLabel($rede)) {
                    $lastRede = $rede;
                }
                continue;
            }

            if ($codigo === ''
                || $this->isInventorySummaryRow($codigo)
                || $this->isColumnHeaderLabel($codigo)
            ) {
                continue;
            }

            $effectiveRede = $rede !== '' ? $rede : $lastRede;
            if ($effectiveRede === ''
                || $this->isGroupHeaderRede($effectiveRede)
                || $this->isColumnHeaderLabel($effectiveRede)
            ) {
                continue;
            }

            if ($rede !== '') {
                $lastRede = $rede;
            }

            $data['inventory'][] = [
                'codigo' => $codigo,
                'veiculo' => $this->text($sheet, $row, self::COL['veiculo']),
                'denominacao' => $this->text($sheet, $row, self::COL['denominacao']),
                'faces' => (int) $this->float($sheet, $row, self::COL['faces']),
                'classe_social' => $this->text($sheet, $row, self::COL['classe_social']),
                'regiao' => $this->text($sheet, $row, self::COL['regiao']),
                'estado' => $this->text($sheet, $row, self::COL['estado']),
                'cidade' => $this->text($sheet, $row, self::COL['cidade']),
                'segmento' => $this->text($sheet, $row, self::COL['segmento']),
                'rede' => $effectiveRede,
                'dias' => (int) $this->float($sheet, $row, self::COL['dias']),
                'publico' => $this->float($sheet, $row, self::COL['publico']),
                'insercoes' => $this->float($sheet, $row, self::COL['insercoes']),
                'impactos' => $this->float($sheet, $row, self::COL['impactos']),
                'desconto' => $this->float($sheet, $row, self::COL['desconto']),
                'bruto_negociado' => $this->float($sheet, $row, self::COL['bruto']),
                'bruto' => $this->float($sheet, $row, self::COL['bruto']),
                'liquido' => $this->float($sheet, $row, self::COL['liquido']),
                'cpm' => $this->float($sheet, $row, self::COL['cpm']),
            ];
        }

        $obsName = $spreadsheet->sheetNameExists('OBSERVAÇÕES') ? 'OBSERVAÇÕES' : (
            $spreadsheet->sheetNameExists('OBSERVACOES') ? 'OBSERVACOES' : null
        );
        if ($obsName !== null) {
            $obsSheet = $spreadsheet->getSheetByName($obsName);
            foreach ($this->rowsWithValues($obsSheet, 'A') as $row) {
                if ($row === 1) {
                    continue;
                }
                $text = $this->text($obsSheet, $row, 'A');
                if ($text !== '') {
                    $data['observacoes'][] = $text;
                }
            }
        }

        if ($data['inventory'] === []) {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }

        $data['totals'] = $this->totals($data['inventory']);

        return $data;
    }

    /** @param list<array<string, mixed>> $inventory */
    private function totals(array $inventory): array
    {
        $t = ['insercoes' => 0.0, 'impactos' => 0.0, 'bruto' => 0.0, 'liquido' => 0.0];
        foreach ($inventory as $row) {
            $t['insercoes'] += (float) $row['insercoes'];
            $t['impactos'] += (float) $row['impactos'];
            $t['bruto'] += (float) $row['bruto'];
            $t['liquido'] += (float) $row['liquido'];
        }
        return $t;
    }

    public static function clearSpreadsheetCache(?string $filePath = null): void
    {
        if ($filePath === null) {
            self::$spreadsheetCache = [];

            return;
        }

        $key = realpath($filePath) ?: $filePath;
        unset(self::$spreadsheetCache[$key]);
    }

    private function loadSpreadsheet(string $filePath): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $key = realpath($filePath) ?: $filePath;
        if (!isset(self::$spreadsheetCache[$key])) {
            self::$spreadsheetCache[$key] = IOFactory::load($filePath);
        }

        return self::$spreadsheetCache[$key];
    }

    private function inventorySheet(string $filePath): Worksheet
    {
        return $this->resolveInventorySheet($this->loadSpreadsheet($filePath));
    }

    private function resolveInventorySheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): Worksheet
    {
        foreach (self::INVENTORY_SHEET_NAMES as $name) {
            if ($spreadsheet->sheetNameExists($name)) {
                return $spreadsheet->getSheetByName($name);
            }
        }

        foreach ($spreadsheet->getSheetNames() as $name) {
            $normalized = $this->normalizeSheetName($name);
            if ($normalized === 'inventario') {
                return $spreadsheet->getSheetByName($name);
            }
        }

        throw new \RuntimeException('Aba INVENTARIO não encontrada na planilha.');
    }

    private function normalizeSheetName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return preg_replace('/\s+/', '', $name) ?? $name;
    }

    private function sheetByName(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $name): Worksheet
    {
        if (!$spreadsheet->sheetNameExists($name)) {
            throw new \RuntimeException("Aba {$name} não encontrada.");
        }
        return $spreadsheet->getSheetByName($name);
    }

    private function findMetadataRow(Worksheet $sheet, ?string $adsId): int
    {
        $candidates = [];
        foreach ($this->rowsWithValues($sheet, self::COL['campanha']) as $row) {
            $rowAds = $this->text($sheet, $row, self::COL['ads_id']);
            $campanha = $this->text($sheet, $row, self::COL['campanha']);
            if ($this->isValidAdsId($rowAds) && $campanha !== '' && !$this->isHeaderMetadata($rowAds, $campanha)) {
                $candidates[] = ['row' => $row, 'ads_id' => $rowAds];
            }
        }
        if ($candidates === []) {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }
        if ($adsId !== null && $adsId !== '') {
            foreach ($candidates as $c) {
                if ($c['ads_id'] === $adsId) {
                    return $c['row'];
                }
            }
            throw new \RuntimeException("AdsID '{$adsId}' não encontrado na planilha.");
        }

        $bestRow = $candidates[0]['row'];
        $bestCount = -1;
        foreach ($candidates as $c) {
            $count = $this->countInventoryRows($sheet, $c['row'], $c['ads_id']);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestRow = $c['row'];
            }
        }
        return $bestRow;
    }

    private function assertInventoryFormat(Worksheet $sheet): void
    {
        $ak1 = $this->normalizeHeaderLabel($this->text($sheet, 1, 'AK'));
        $al1 = $this->normalizeHeaderLabel($this->text($sheet, 1, 'AL'));

        if ($ak1 === 'imagem' && $al1 === 'adsid') {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }

        $expectedHeaders = [
            'B' => ['codigo', 'código'],
            'J' => ['rede'],
            'AK' => ['adsid'],
            'AL' => ['agencia', 'agência'],
            'AM' => ['anunciante'],
            'AN' => ['planejador'],
            'AO' => ['campanha'],
        ];

        $mismatches = 0;
        foreach ($expectedHeaders as $col => $accepted) {
            $actual = $this->normalizeHeaderLabel($this->text($sheet, 1, $col));
            if ($actual === '') {
                $mismatches++;
                continue;
            }
            if (!$this->headerMatches($actual, $accepted)) {
                $mismatches++;
            }
        }

        if ($mismatches >= 3) {
            throw new \RuntimeException(self::FORMAT_ERROR);
        }
    }

    private function normalizeHeaderLabel(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return preg_replace('/\s+/', '', $ascii !== false ? $ascii : $normalized) ?? $normalized;
    }

    /** @param list<string> $accepted */
    private function headerMatches(string $actual, array $accepted): bool
    {
        foreach ($accepted as $label) {
            if ($actual === $this->normalizeHeaderLabel($label)) {
                return true;
            }
        }

        return false;
    }

    private function isValidAdsId(string $adsId): bool
    {
        return (bool) preg_match('/^[a-f0-9]{10,16}$/i', trim($adsId));
    }

    private function isHeaderMetadata(string $adsId, string $campanha): bool
    {
        if ($this->isValidAdsId($adsId)) {
            return false;
        }

        $set = [mb_strtolower(trim($adsId)), mb_strtolower(trim($campanha))];
        foreach (['adsid', 'campanha', 'anunciante', 'agência', 'agencia', 'planejador', 'imagem'] as $h) {
            if (in_array($h, $set, true)) {
                return true;
            }
        }

        return str_contains(mb_strtolower($adsId), 'http');
    }

    private function countInventoryRows(Worksheet $sheet, int $metadataRow, string $campaignAdsId): int
    {
        $count = 0;
        $lastRede = '';
        $highestRow = $this->inventoryHighestRow($sheet);

        for ($row = $metadataRow; $row <= $highestRow; $row++) {
            if ($this->isNextCampaignMetadataRow($sheet, $row, $metadataRow, $campaignAdsId)) {
                break;
            }

            $codigo = $this->text($sheet, $row, self::COL['codigo']);
            $rede = $this->text($sheet, $row, self::COL['rede']);

            if ($codigo === '' && $rede !== '') {
                if (!$this->isGroupHeaderRede($rede) && !$this->isColumnHeaderLabel($rede)) {
                    $lastRede = $rede;
                }
                continue;
            }

            if ($codigo === ''
                || $this->isInventorySummaryRow($codigo)
                || $this->isColumnHeaderLabel($codigo)
            ) {
                continue;
            }

            $effectiveRede = $rede !== '' ? $rede : $lastRede;
            if ($effectiveRede === ''
                || $this->isGroupHeaderRede($effectiveRede)
                || $this->isColumnHeaderLabel($effectiveRede)
            ) {
                continue;
            }

            if ($rede !== '') {
                $lastRede = $rede;
            }

            $count++;
        }

        return $count;
    }

    private function inventoryHighestRow(Worksheet $sheet): int
    {
        $highest = max(
            $sheet->getHighestRow(),
            $this->maxRowWithValues($sheet, self::COL['codigo']),
            $this->maxRowWithValues($sheet, self::COL['rede']),
        );

        return max($highest, 1);
    }

    private function maxRowWithValues(Worksheet $sheet, string $col): int
    {
        $rows = $this->rowsWithValues($sheet, $col);

        return $rows === [] ? 0 : max($rows);
    }

    private function isCampaignMetadataRow(Worksheet $sheet, int $row): bool
    {
        $rowAds = $this->text($sheet, $row, self::COL['ads_id']);
        $campanha = $this->text($sheet, $row, self::COL['campanha']);

        return $this->isValidAdsId($rowAds) && $campanha !== '' && !$this->isHeaderMetadata($rowAds, $campanha);
    }

    private function isNextCampaignMetadataRow(
        Worksheet $sheet,
        int $row,
        int $metadataRow,
        string $campaignAdsId,
    ): bool {
        if ($row <= $metadataRow || !$this->isCampaignMetadataRow($sheet, $row)) {
            return false;
        }

        return $this->text($sheet, $row, self::COL['ads_id']) !== $campaignAdsId;
    }

    private function isColumnHeaderLabel(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, [
            'código',
            'codigo',
            'rede',
            'veículo',
            'veiculo',
            'denominação',
            'denominacao',
        ], true);
    }

    private function isGroupHeaderRede(string $rede): bool
    {
        $normalized = mb_strtoupper(trim($rede));

        return str_starts_with($normalized, 'PRAÇA ')
            || str_starts_with($normalized, 'PRACA ')
            || $normalized === 'TOTAL'
            || $normalized === 'TOTAIS';
    }

    private function isInventorySummaryRow(string $codigo): bool
    {
        $normalized = mb_strtoupper(trim($codigo));

        return in_array($normalized, ['TOTAL', 'TOTAIS', 'SUBTOTAL', 'SUB-TOTAL'], true);
    }

    /** @return list<int> */
    private function rowsWithValues(Worksheet $sheet, string $col): array
    {
        $rows = [];
        $highest = $sheet->getHighestRow();
        for ($row = 1; $row <= $highest; $row++) {
            $val = $sheet->getCell($col . $row)->getCalculatedValue();
            if ($val !== null && $val !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function text(Worksheet $sheet, int $row, string $col): string
    {
        $coordinate = $col . $row;
        $val = $sheet->getCell($coordinate)->getCalculatedValue();
        $text = trim((string) ($val ?? ''));
        if ($text !== '') {
            return $text;
        }

        foreach ($sheet->getMergeCells() as $range) {
            if (!Coordinate::coordinateIsInsideRange($coordinate, $range)) {
                continue;
            }
            [$startCell] = explode(':', $range);
            $mergedVal = $sheet->getCell($startCell)->getCalculatedValue();

            return trim((string) ($mergedVal ?? ''));
        }

        return '';
    }

    private function float(Worksheet $sheet, int $row, string $col): float
    {
        $val = $sheet->getCell($col . $row)->getCalculatedValue();
        if ($val === null || $val === '') {
            return 0.0;
        }
        return (float) $val;
    }

    private function dateValue(Worksheet $sheet, int $row, string $col): ?string
    {
        $cell = $sheet->getCell($col . $row);
        $val = $cell->getCalculatedValue();
        if ($val === null || $val === '') {
            return null;
        }
        if (is_numeric($val)) {
            return ExcelDate::excelToDateTimeObject((float) $val)->format('Y-m-d');
        }
        $text = trim((string) $val);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $text, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return $text;
        }
        return null;
    }
}

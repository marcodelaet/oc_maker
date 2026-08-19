<?php

declare(strict_types=1);

namespace OcMaker;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExcelService
{
    private const COL = [
        'codigo' => 'B',
        'rede' => 'J',
        'impactos' => 'O',
        'insercoes' => 'P',
        'bruto' => 'U',
        'liquido' => 'V',
        'ads_id' => 'AK',
        'agencia' => 'AL',
        'anunciante' => 'AM',
        'planejador' => 'AN',
        'campanha' => 'AO',
        'inicio' => 'AQ',
        'termino' => 'AR',
    ];

    /** @return list<array<string, string>> */
    public function listCampaigns(string $filePath): array
    {
        $sheet = $this->inventorySheet($filePath);
        $campaigns = [];
        foreach ($this->rowsWithValues($sheet, self::COL['campanha']) as $row) {
            $adsId = $this->text($sheet, $row, self::COL['ads_id']);
            $campanha = $this->text($sheet, $row, self::COL['campanha']);
            if ($adsId !== '' && $campanha !== '' && !$this->isHeaderMetadata($adsId, $campanha)) {
                $campaigns[] = [
                    'ads_id' => $adsId,
                    'campanha' => $campanha,
                    'anunciante' => $this->text($sheet, $row, self::COL['anunciante']),
                    'agencia' => $this->text($sheet, $row, self::COL['agencia']),
                    'row' => (string) $row,
                ];
            }
        }
        return $campaigns;
    }

    /** @return array<string, mixed> */
    public function loadCampaign(string $filePath, ?string $adsId = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $this->sheetByName($spreadsheet, 'INVENTARIO');
        $metadataRow = $this->findMetadataRow($sheet, $adsId);
        $campaignAdsId = $this->text($sheet, $metadataRow, self::COL['ads_id']);

        $data = [
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

        $currentAds = $campaignAdsId;
        foreach ($this->rowsWithValues($sheet, self::COL['codigo']) as $row) {
            if ($row < $metadataRow) {
                continue;
            }
            $rowAds = $this->text($sheet, $row, self::COL['ads_id']);
            if ($rowAds !== '') {
                if ($row > $metadataRow && $rowAds !== $campaignAdsId) {
                    break;
                }
                $currentAds = $rowAds;
            } elseif ($row > $metadataRow && $currentAds !== $campaignAdsId) {
                continue;
            }

            $codigo = $this->text($sheet, $row, self::COL['codigo']);
            $rede = $this->text($sheet, $row, self::COL['rede']);
            if ($codigo === '' || $rede === '') {
                continue;
            }

            $data['inventory'][] = [
                'codigo' => $codigo,
                'rede' => $rede,
                'insercoes' => $this->float($sheet, $row, self::COL['insercoes']),
                'impactos' => $this->float($sheet, $row, self::COL['impactos']),
                'bruto' => $this->float($sheet, $row, self::COL['bruto']),
                'liquido' => $this->float($sheet, $row, self::COL['liquido']),
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

    private function inventorySheet(string $filePath): Worksheet
    {
        return $this->sheetByName(IOFactory::load($filePath), 'INVENTARIO');
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
            if ($campanha !== '' && $rowAds !== '' && !$this->isHeaderMetadata($rowAds, $campanha)) {
                $candidates[] = ['row' => $row, 'ads_id' => $rowAds];
            }
        }
        if ($candidates === []) {
            throw new \RuntimeException('Não foi encontrada linha de metadados na aba INVENTARIO.');
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
            $count = 0;
            $currentAds = $c['ads_id'];
            foreach ($this->rowsWithValues($sheet, self::COL['codigo']) as $dataRow) {
                if ($dataRow < $c['row']) {
                    continue;
                }
                $nextAds = $this->text($sheet, $dataRow, self::COL['ads_id']);
                if ($nextAds !== '') {
                    if ($dataRow > $c['row'] && $nextAds !== $c['ads_id']) {
                        break;
                    }
                    $currentAds = $nextAds;
                }
                if ($currentAds !== $c['ads_id']) {
                    continue;
                }
                if ($this->text($sheet, $dataRow, self::COL['rede']) !== '') {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestRow = $c['row'];
            }
        }
        return $bestRow;
    }

    private function isHeaderMetadata(string $adsId, string $campanha): bool
    {
        $set = [mb_strtolower(trim($adsId)), mb_strtolower(trim($campanha))];
        foreach (['adsid', 'campanha', 'anunciante', 'agência', 'agencia'] as $h) {
            if (in_array($h, $set, true)) {
                return true;
            }
        }
        return false;
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
        $val = $sheet->getCell($col . $row)->getCalculatedValue();
        return trim((string) ($val ?? ''));
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

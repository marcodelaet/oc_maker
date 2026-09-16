<?php

declare(strict_types=1);

namespace OcMaker;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ActivityLogExportService
{
    /** @var list<array{key:string,label:string}> */
    public const COLUMNS = [
        ['key' => 'id', 'label' => 'ID'],
        ['key' => 'created_at', 'label' => 'Data/hora'],
        ['key' => 'actor_label', 'label' => 'Usuário'],
        ['key' => 'actor_type', 'label' => 'Tipo ator'],
        ['key' => 'action', 'label' => 'Ação'],
        ['key' => 'message', 'label' => 'Mensagem'],
        ['key' => 'entity_type', 'label' => 'Tipo entidade'],
        ['key' => 'entity_id', 'label' => 'ID entidade'],
        ['key' => 'ip_address', 'label' => 'IP'],
        ['key' => 'client_os', 'label' => 'SO'],
        ['key' => 'client_browser', 'label' => 'Navegador'],
        ['key' => 'client_platform', 'label' => 'Plataforma'],
        ['key' => 'reverse_hostname', 'label' => 'Host reverso'],
        ['key' => 'user_agent', 'label' => 'User-Agent'],
        ['key' => 'details_json', 'label' => 'Detalhes (JSON)'],
    ];

    /** @param list<array<string,mixed>> $entries */
    public function streamCsv(array $entries, string $filename): never
    {
        $spreadsheet = $this->buildSpreadsheet($entries);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(';');
        $writer->setEnclosure('"');
        $writer->setUseBOM(true);
        $writer->save('php://output');
        exit;
    }

    /** @param list<array<string,mixed>> $entries */
    public function streamXlsx(array $entries, string $filename): never
    {
        $spreadsheet = $this->buildSpreadsheet($entries);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    /** @param list<array<string,mixed>> $entries */
    public function streamPdf(array $entries, string $filename): never
    {
        $html = $this->buildPdfHtml($entries);
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dompdf->output();
        exit;
    }

    /** @param list<array<string,mixed>> $entries */
    private function buildSpreadsheet(array $entries): Spreadsheet
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Log de eventos');

        $col = 1;
        foreach (self::COLUMNS as $column) {
            $ws->setCellValue([$col, 1], $column['label']);
            $col++;
        }

        $rowNum = 2;
        foreach ($entries as $entry) {
            $col = 1;
            foreach (self::COLUMNS as $column) {
                $ws->setCellValue([$col, $rowNum], $this->cellValue($entry, $column['key']));
                $col++;
            }
            $rowNum++;
        }

        return $sheet;
    }

    /** @param array<string,mixed> $entry */
    private function cellValue(array $entry, string $key): string
    {
        if ($key === 'details_json') {
            if (isset($entry['details']) && is_array($entry['details'])) {
                return json_encode($entry['details'], JSON_UNESCAPED_UNICODE) ?: '';
            }

            return (string) ($entry['details_json'] ?? '');
        }

        $value = $entry[$key] ?? '';

        return is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<array<string,mixed>> $entries */
    private function buildPdfHtml(array $entries): string
    {
        $head = '';
        foreach (self::COLUMNS as $column) {
            $head .= '<th>' . htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8') . '</th>';
        }

        $body = '';
        foreach ($entries as $entry) {
            $body .= '<tr>';
            foreach (self::COLUMNS as $column) {
                $text = $this->cellValue($entry, $column['key']);
                if (strlen($text) > 120) {
                    $text = substr($text, 0, 117) . '…';
                }
                $body .= '<td>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $body .= '</tr>';
        }

        $generated = date('d/m/Y H:i:s');
        $count = count($entries);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:7px;color:#0f172a}
h1{font-size:14px;margin:0 0 4px}
.meta{font-size:8px;color:#64748b;margin:0 0 10px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #cbd5e1;padding:3px 4px;text-align:left;vertical-align:top}
th{background:#eff6ff;font-size:7px}
tr:nth-child(even){background:#f8fafc}
</style></head>
<body>
<h1>Log de eventos — OC Maker</h1>
<p class="meta">Gerado em {$generated} · {$count} registro(s)</p>
<table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
</body></html>
HTML;
    }
}

<?php

declare(strict_types=1);

namespace OcMaker;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfService
{
    public function __construct(private readonly TechFeeService $fees)
    {
    }

    /** @param array<string, mixed> $campaign */
    /** @param array<string, mixed> $options */
    public function render(array $campaign, array $options): string
    {
        $fin = $this->fees->financials(
            $campaign,
            (string) ($options['tipo_venda'] ?? 'SSP'),
            (string) ($options['planejador_ssp'] ?? '')
        );

        ob_start();
        include dirname(__DIR__) . '/templates/pdf.php';
        $html = (string) ob_get_clean();

        $dompdfOptions = new Options();
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    public static function formatBrl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function formatNumber(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    public static function formatDateBr(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        $parts = explode('-', $iso);
        if (count($parts) !== 3) {
            return $iso;
        }
        return "{$parts[2]}/{$parts[1]}/{$parts[0]}";
    }
}

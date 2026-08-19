<?php

declare(strict_types=1);

namespace OcMaker;

final class PaymentTermService
{
    public const OPCOES = [15, 30, 60, 70, 90];
    public const UNIDADES = ['Dias', 'DFM'];

    public static function calcPaymentDate(?string $terminoIso, int $prazoDias, string $unidade): ?\DateTimeImmutable
    {
        if ($terminoIso === null || $terminoIso === '' || $prazoDias <= 0) {
            return null;
        }
        $termino = new \DateTimeImmutable($terminoIso);
        if ($unidade === 'DFM') {
            $lastDay = $termino->modify('last day of this month');
            return $lastDay->modify('+' . $prazoDias . ' days');
        }
        return $termino->modify('+' . $prazoDias . ' days');
    }

    public static function formatLabel(?string $terminoIso, int $prazoDias, string $unidade): string
    {
        $payment = self::calcPaymentDate($terminoIso, $prazoDias, $unidade);
        $dateStr = $payment ? $payment->format('d/m/Y') : '—';
        return "Prazo para Pagamento: {$prazoDias} dias ({$dateStr})";
    }
}

final class PdfBrand
{
    public const COMPANY_NAME = 'Converta Ads Comercialização de Mídias Ltda';
    public const ADDRESS = 'Rua Capitão Rosa, 376';
    public const CITY = 'Jardim Paulistano - São Paulo - SP - CEP 01443-900';
    public const CNPJ = 'CNPJ: 60.436.341/0001-36';
    public const FOOTER_NOTE = '* Não esquecer de enviar / anexar os criativos da campanha para solicitar aprovação das redes';

    public static function logoDataUri(): string
    {
        $png = dirname(__DIR__) . '/public/assets/logo_converta.png';
        $svg = dirname(__DIR__) . '/public/assets/logo_converta.svg';
        $path = is_file($png) ? $png : (is_file($svg) ? $svg : '');
        if ($path === '') {
            return '';
        }
        $mime = str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    public static function checkbox(bool $checked): string
    {
        if ($checked) {
            return '<span style="font-size: 1.1rem" class="checkbox checked">✓</span>';
        }
        return '<span class="checkbox"></span>';
    }

    /** @param list<string> $observacoes */
    public static function formatObservacoes(array $observacoes): string
    {
        if ($observacoes === []) {
            return '<p class="obs-line">—</p>';
        }
        $html = '';
        foreach ($observacoes as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $isHeading = (bool) preg_match('/^[A-ZÁÉÍÓÚÃÕÇ0-9][A-ZÁÉÍÓÚÃÕÇ0-9\s\/\-]+:$/u', $line);
            $class = $isHeading ? 'obs-heading' : 'obs-line';
            $html .= '<p class="' . $class . '">' . htmlspecialchars($line) . '</p>';
        }
        return $html ?: '<p class="obs-line">—</p>';
    }
}

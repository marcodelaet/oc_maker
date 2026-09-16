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

    /** 15 DFM após o prazo de recebimento (PI): último dia do mês do PI + 15 dias. */
    public static function calcRepasseDate(\DateTimeInterface $piDate, int $prazoDias = 15): \DateTimeImmutable
    {
        $immutable = \DateTimeImmutable::createFromInterface($piDate);

        return $immutable->modify('last day of this month')->modify('+' . $prazoDias . ' days');
    }

    public static function formatDateBr(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }

        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }
}

final class PdfBrand
{
    public const COMPANY_NAME = 'Retail Media';
    public const FOOTER_NOTE = '* Não esquecer de enviar / anexar os criativos da campanha para solicitar aprovação das redes';

    public static function logoDataUri(): string
    {
        $candidates = [
            dirname(__DIR__) . '/public/assets/logo_retail_media.png',
            dirname(__DIR__) . '/public/assets/logo_retail_media.jpg',
            dirname(__DIR__) . '/public/assets/logo_converta.png',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

                return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
            }
        }

        return '';
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

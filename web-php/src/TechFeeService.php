<?php

declare(strict_types=1);

namespace OcMaker;

final class TechFeeService
{
    /** @var array<string, array<string, float|int>> */
    private array $fees;

    public function __construct(?string $jsonPath = null)
    {
        $path = $jsonPath ?? dirname(__DIR__) . '/config/tech_fees.json';
        $this->fees = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function percent(string $tipoVenda, string $planejador): float
    {
        $group = $this->fees[$tipoVenda] ?? [];
        if ($planejador !== '' && isset($group[$planejador])) {
            return (float) $group[$planejador];
        }

        return (float) ($group['default'] ?? 0);
    }

    public function mediaCostFromLiquido(float $liquido, float $feePercent): float
    {
        $denom = max(0.0001, 1 - ($feePercent / 100));

        return $liquido / $denom;
    }

    /** @return array<string, mixed> */
    public function financials(array $campaign, string $tipoVenda, string $planejador): array
    {
        $totals = $campaign['totals'];
        $budgetBruto = (float) $totals['bruto'];
        $budgetLiquido = (float) $totals['liquido'];
        $feePercent = $this->percent($tipoVenda, $planejador);
        $feeRate = $feePercent / 100;
        $denom = max(0.0001, 1 - $feeRate);

        $brutoBase = $budgetBruto / $denom;
        $feeValue = $budgetBruto * $feeRate / $denom;
        $impactos = (float) $totals['impactos'];
        $cpm = $impactos > 0 ? ($budgetBruto / $impactos) * 1000 : 0.0;

        return [
            'totals' => $totals,
            'valor_ssp' => $brutoBase,
            'bruto_base' => $brutoBase,
            'faturamento' => $budgetBruto,
            'fee_percent' => $feePercent,
            'fee_value' => $feeValue,
            'valor_publisher' => $budgetLiquido,
            'valor_publisher_pdf' => $budgetBruto,
            'cpm' => $cpm,
        ];
    }
}

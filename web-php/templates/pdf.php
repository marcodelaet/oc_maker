<?php
/** @var array<string, mixed> $campaign */
/** @var array<string, mixed> $options */
/** @var array<string, mixed> $fin */

use OcMaker\PaymentTermService;
use OcMaker\PdfBrand;
use OcMaker\PdfService;

$fmt = [PdfService::class, 'formatBrl'];
$num = [PdfService::class, 'formatNumber'];
$date = [PdfService::class, 'formatDateBr'];

$tipoVenda = (string) ($options['tipo_venda'] ?? '');
$tipoDeal = (string) ($options['tipo_deal'] ?? '');
$dealId = (string) ($options['deal_id'] ?? '');
$showOcInformeSsp = $tipoVenda === 'SSP';
$anunciante = trim((string) ($campaign['anunciante'] ?? '') . (
    !empty($campaign['agencia']) ? ' / ' . $campaign['agencia'] : ''
));
$prazoDias = (int) ($options['prazo_pagamento'] ?? 15);
$prazoUnidade = (string) ($options['prazo_unidade'] ?? 'DFM');
$prazoLabel = PaymentTermService::formatLabel($campaign['termino'] ?? null, $prazoDias, $prazoUnidade);
$logo = PdfBrand::logoDataUri();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 28px 32px 40px 32px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 0; }
  .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .header-table td { vertical-align: top; border: none; padding: 0; }
  .company { text-align: right; font-size: 8px; line-height: 1.45; color: #333; }
  .title-bar { background: #2b2b2b; color: #fff; text-align: center; font-size: 14px; font-weight: bold; padding: 8px 0; margin: 8px 0 6px; }
  .doc-id { text-align: center; font-size: 11px; font-weight: bold; margin-bottom: 12px; }
  .meta-grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .meta-grid td { width: 50%; vertical-align: top; padding: 2px 8px 2px 0; border: none; font-size: 9px; }
  .meta-grid .label { font-weight: bold; }
  .data-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .data-table th { background: #f97316; color: #fff; font-size: 7.5px; padding: 5px 4px; border: 0.5px solid #e5e5e5; }
  .data-table td { padding: 4px; border: 0.5px solid #e5e5e5; font-size: 8px; }
  .data-table tr.alt td { background: #f5f5f5; }
  .data-table tr.totals td { background: #f97316; color: #fff; font-weight: bold; }
  .right { text-align: right; }
  .financial { margin-top: 8px; text-align: right; font-size: 9px; line-height: 1.6; }
  .financial .label { font-weight: bold; }
  .checks { width: 100%; margin: 12px 0; }
  .checks td { width: 50%; font-size: 9px; padding: 2px 0; border: none; }
  .checkbox { display: inline-block; width: 11px; height: 11px; border: 1px solid #333; text-align: center; line-height: 10px; font-size: 9px; margin-left: 4px; vertical-align: middle; }
  .checkbox.checked { font-weight: bold; }
  .obs-title { font-weight: bold; font-size: 9px; margin: 0 0 4px; }
  .obs-line { font-size: 8px; line-height: 1.35; margin: 2px 0; color: #333; }
  .obs-heading { font-size: 8px; font-weight: bold; margin: 6px 0 2px; color: #333; }
  .prazo { font-weight: bold; font-size: 9px; margin-top: 14px; }
  .footer-note { position: fixed; bottom: 10px; left: 32px; right: 32px; font-size: 7px; color: #888; font-style: italic; }
</style>
</head>
<body>

<table class="header-table">
  <tr>
    <td style="width:100%;text-align:center">
      <?php if ($logo !== ''): ?>
        <img src="<?= $logo ?>" alt="Retail Media" style="height:52px;width:auto">
      <?php else: ?>
        <strong style="font-size:18px;color:#111">Retail Media</strong>
      <?php endif; ?>
    </td>
  </tr>
</table>

<div class="title-bar"><?= htmlspecialchars((string) $options['document_title']) ?></div>
<div class="doc-id">#<?= htmlspecialchars((string) $options['document_id']) ?></div>

<table class="meta-grid">
  <tr>
    <td><span class="label">Anunciante:</span> <?= htmlspecialchars($anunciante ?: '—') ?></td>
    <td><span class="label">Campanha:</span> <?= htmlspecialchars((string) ($campaign['campanha'] ?? '—')) ?></td>
  </tr>
  <tr>
    <td><span class="label">Data de Início:</span> <?= $date($campaign['inicio'] ?? null) ?: '—' ?></td>
    <td><span class="label">Término:</span> <?= $date($campaign['termino'] ?? null) ?: '—' ?></td>
  </tr>
  <tr>
    <td><span class="label">Tipo de Venda:</span> <?= htmlspecialchars($tipoVenda ?: '—') ?></td>
    <?php if ($tipoDeal !== ''): ?>
      <td><span class="label">Tipo de DEAL:</span> <?= htmlspecialchars($tipoDeal) ?></td>
    <?php else: ?><td></td><?php endif; ?>
  </tr>
  <tr>
    <td><span class="label">Planejador:</span> <?= htmlspecialchars((string) ($options['planejador_ssp'] ?? '—')) ?></td>
    <?php if ($dealId !== ''): ?>
      <td><span class="label">Deal ID:</span> <?= htmlspecialchars($dealId) ?></td>
    <?php else: ?><td></td><?php endif; ?>
  </tr>
  <?php if ($showOcInformeSsp): ?>
  <tr>
    <td><span class="label">OC/Informe (SSP):</span> <?= htmlspecialchars((string) ($options['oc_informe_ssp'] ?? '—')) ?></td>
    <td></td>
  </tr>
  <?php endif; ?>
</table>

<table class="data-table">
  <thead>
    <tr>
      <th>ID Loja - Converta</th>
      <th>Rede</th>
      <th class="right">Total Inserções</th>
      <th class="right">Total Impactos</th>
      <th class="right">Budget Media Cost</th>
      <th class="right">Budget Publisher</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($campaign['inventory'] as $i => $row): ?>
    <tr class="<?= $i % 2 === 1 ? 'alt' : '' ?>">
      <td><?= htmlspecialchars((string) $row['codigo']) ?></td>
      <td><?= htmlspecialchars((string) $row['rede']) ?></td>
      <td class="right"><?= $num((float) $row['insercoes']) ?></td>
      <td class="right"><?= $num((float) $row['impactos']) ?></td>
      <td class="right"><?= $fmt((float) $row['bruto']) ?></td>
      <td class="right"><?= $fmt((float) $row['liquido']) ?></td>
    </tr>
  <?php endforeach; ?>
    <tr class="totals">
      <td>TOTAIS</td><td></td>
      <td class="right"><?= $num((float) $fin['totals']['insercoes']) ?></td>
      <td class="right"><?= $num((float) $fin['totals']['impactos']) ?></td>
      <td class="right"><?= $fmt((float) $fin['totals']['bruto']) ?></td>
      <td class="right"><?= $fmt((float) $fin['totals']['liquido']) ?></td>
    </tr>
  </tbody>
</table>

<div class="financial">
  <div><span class="label">VALOR LÍQUIDO (SSP):</span> <?= $fmt((float) $fin['valor_ssp']) ?></div>
  <div><span class="label">TECH FEE (%):</span> <?= number_format((float) $fin['fee_percent'], 0, ',', '.') ?> &nbsp; <?= $fmt((float) $fin['fee_value']) ?></div>
  <div><span class="label">VALOR LÍQUIDO (PUBLISHER):</span> <?= $fmt((float) $fin['valor_publisher']) ?></div>
  <div><span class="label">CPM MÉDIO:</span> <?= $fmt((float) $fin['cpm']) ?></div>
</div>

<table class="checks">
  <tr>
    <td>Checking Fotográfico? <?= PdfBrand::checkbox(!empty($options['checking_fotografico'])) ?></td>
    <td class="right">Relatórios Adicionais? <?= PdfBrand::checkbox(!empty($options['relatorios_adicionais'])) ?></td>
  </tr>
</table>

<p class="obs-title">Observações:</p>
<?= PdfBrand::formatObservacoes($campaign['observacoes'] ?? []) ?>

<p class="prazo"><?= htmlspecialchars($prazoLabel) ?></p>

<div class="footer-note"><?= htmlspecialchars(PdfBrand::FOOTER_NOTE) ?></div>
</body>
</html>

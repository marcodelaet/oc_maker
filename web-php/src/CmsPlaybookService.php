<?php

declare(strict_types=1);

namespace OcMaker;

final class CmsPlaybookService
{
    /** @param array<string, mixed> $doc @param list<string> $onsignScreens @param list<string> $invianScreens */
    public function build(array $doc, array $deals, array $onsignScreens, array $invianScreens): array
    {
        $slots = max(1, (int) ($doc['campaign_slots'] ?? 1));
        $planningUrl = 'https://planning.invian.net/planner/?vehicle=CONVERTAADS&adsid=' . urlencode((string) ($doc['ads_id'] ?? ''));

        return [
            'planning_url' => $planningUrl,
            'campaign_slots' => $slots,
            'xibo' => [
                'title' => 'Xibo',
                'summary' => 'Encaminhar para execução de mídia tradicional',
                'steps' => [
                    'As telas Xibo não entram na programática neste fluxo.',
                    'Encaminhar para execução de mídia tradicional.',
                ],
            ],
            'onsign' => [
                'title' => 'OnSign',
                'steps' => $this->onsignSteps($doc, $deals, $onsignScreens, $slots, $planningUrl),
                'player_codes' => $onsignScreens,
                'orgs' => ['Drogaria São Paulo', 'Pague Menos', 'INSTORE', 'Shoppings', 'SONDA', 'SWIFT'],
            ],
            'invian' => [
                'title' => 'Invian',
                'steps' => $this->invianSteps($doc, $deals, $invianScreens, $slots),
                'face_codes' => $invianScreens,
            ],
        ];
    }

    /** @param array<string, mixed> $doc @param list<array<string, mixed>> $deals */
    private function onsignSteps(array $doc, array $deals, array $screens, int $slots, string $planningUrl): array
    {
        $steps = [
            'Entrar no OnSign: https://app.onsign.tv/',
            'Verificar slots no Planning: ' . $planningUrl,
            'Criar grupo(s) de players em https://app.onsign.tv/player-groups/add/',
        ];

        foreach ($deals as $i => $deal) {
            $groupNames = $this->programmaticNamesForDeal($doc, $deal, $slots);
            $dealLabel = 'Grupo Deal ' . ($deal['deal_id'] ?? ($i + 1)) . ':';
            $steps[] = count($groupNames) === 1
                ? $dealLabel . ' ' . $groupNames[0]
                : $dealLabel . "\n" . implode("\n", $groupNames);
            $steps[] = 'Tags do grupo: ' . mb_strtoupper((string) ($doc['planejador_ssp'] ?? ''));
        }

        $steps[] = 'Se houver telas de Orgs (DSP, Pague Menos, INSTORE, Shoppings, SONDA, SWIFT), acesse a Org em https://app.onsign.tv/accounts/brand/ antes de criar o grupo.';
        $steps[] = $screens !== []
            ? 'Adicionar em "Players no Grupo" os códigos OnSign da lista acima (use "Copiar todos" para colar em lote no OnSign).'
            : '(nenhuma tela OnSign cadastrada)';
        $steps[] = 'Conteúdo → pasta Campanhas → Ano → Mês → _' . (string) ($doc['planejador_ssp'] ?? '') . ' → Anunciante → Campanha → Creatives (subir mídias)';

        $appNames = $this->programmaticNamesForAllDeals($doc, $deals, $slots);
        $appLabel = count($appNames) === 1
            ? 'Na pasta da campanha, criar App "Anúncio programático":'
            : 'Na pasta da campanha, criar Apps "Anúncio programático":';
        $steps[] = count($appNames) === 1
            ? $appLabel . ' ' . $appNames[0]
            : $appLabel . "\n" . implode("\n", $appNames);

        $steps[] = 'Configurar as Restrições de data para a execução da Campanha: ' . $this->formatPeriodRestrictions($doc);
        $steps[] = 'Aguardar aprovação das redes e publicar nos grupos. Depois sinalizar campanha iniciada no OnSign.';

        return $steps;
    }

    /** @param array<string, mixed> $doc @param list<array<string, mixed>> $deals */
    private function invianSteps(array $doc, array $deals, array $screens, int $slots): array
    {
        $contract = trim((string) ($doc['oc_informe_ssp'] ?? ''));
        if ($contract === '') {
            $contract = (string) ($doc['ads_id'] ?? '');
        }

        $dealIds = array_map(static fn(array $d): string => (string) ($d['deal_id'] ?? ''), $deals);
        $dealIds = array_values(array_filter($dealIds));

        $description = 'Contrato ' . mb_strtoupper((string) ($doc['planejador_ssp'] ?? '')) . ' ' . $contract
            . ' | Planning ID: ' . ($doc['ads_id'] ?? '')
            . ' | DEAL ID: ' . ($dealIds[0] ?? '—');

        return [
            'Login: https://web.invian.com/sign-in',
            'Campanhas: https://web.invian.com/cms/campaigns',
            'Nova campanha: https://web.invian.com/cms/campaigns/details',
            'Nome: ' . $this->programmaticName($doc),
            'Anunciante: ' . ($doc['anunciante'] ?? ''),
            'Tipo de operação: Programática',
            'Intervalo: ' . $this->formatPeriodLong($doc),
            'Código de contrato: ' . $contract,
            'Origem: ' . ($doc['planejador_ssp'] ?? ''),
            'Descrição sugerida: ' . $description,
            'Artes: aguardar envio da SSP (ficará em branco inicialmente)',
            $screens !== []
                ? 'Atribuir as faces Invian da lista acima (copie cada código individualmente).'
                : 'Faces Invian: (nenhuma face Invian cadastrada)',
            'Distribuição / slots (' . $slots . '): configurar após o Invian receber as artes da SSP.',
        ];
    }

    /** @param array<string, mixed> $doc */
    public function programmaticName(array $doc, ?string $suffix = null): string
    {
        $parts = [
            'PROGRAMATICA',
            mb_strtoupper((string) ($doc['planejador_ssp'] ?? '')),
            mb_strtoupper((string) ($doc['agencia'] ?? '')),
            mb_strtoupper((string) ($doc['anunciante'] ?? '')),
            mb_strtoupper((string) ($doc['campanha'] ?? '')),
            $this->formatPeriodShort($doc),
            (string) ($doc['ads_id'] ?? ''),
        ];
        if ($suffix !== null && $suffix !== '') {
            $parts[] = $suffix;
        }

        return implode(' | ', array_values(array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $deal @return list<string> */
    private function programmaticNamesForDeal(array $doc, array $deal, int $fallbackSlots): array
    {
        $slotCount = max(1, (int) ($deal['slots'] ?? $fallbackSlots));
        $names = [];
        for ($slot = 1; $slot <= $slotCount; $slot++) {
            $names[] = $this->programmaticName($doc, $this->nameSuffix($deal['screen_type'] ?? null, $slot));
        }

        return $names;
    }

    /** @param array<string, mixed> $doc @param list<array<string, mixed>> $deals @return list<string> */
    private function programmaticNamesForAllDeals(array $doc, array $deals, int $fallbackSlots): array
    {
        if ($deals === []) {
            return $this->programmaticNamesForDeal($doc, ['slots' => $fallbackSlots], $fallbackSlots);
        }

        $names = [];
        foreach ($deals as $deal) {
            array_push($names, ...$this->programmaticNamesForDeal($doc, $deal, $fallbackSlots));
        }

        return $names;
    }

    private function nameSuffix(?string $screenType, int $slot): string
    {
        $type = trim((string) $screenType);
        if ($type === '') {
            return 'Slot ' . $slot;
        }

        return mb_strtoupper($type) . ' | Slot ' . $slot;
    }

    /** @param array<string, mixed> $doc */
    private function formatPeriodShort(array $doc): string
    {
        $inicio = $this->formatDatePart($doc['inicio'] ?? null, 'd.m');
        $termino = $this->formatDatePart($doc['termino'] ?? null, 'd.m.y');

        return $inicio . ' a ' . $termino;
    }

    /** @param array<string, mixed> $doc */
    private function formatPeriodLong(array $doc): string
    {
        $inicio = $this->formatDatePart($doc['inicio'] ?? null, 'd/m/Y');
        $termino = $this->formatDatePart($doc['termino'] ?? null, 'd/m/Y');

        return $inicio . ' — ' . $termino;
    }

    /** @param array<string, mixed> $doc */
    private function formatPeriodRestrictions(array $doc): string
    {
        $inicio = $this->formatDatePart($doc['inicio'] ?? null, 'd/m/Y');
        $termino = $this->formatDatePart($doc['termino'] ?? null, 'd/m/Y');

        return $inicio . ' - ' . $termino;
    }

    private function formatDatePart(mixed $iso, string $format): string
    {
        if (!$iso) {
            return '—';
        }
        $ts = strtotime((string) $iso);

        return $ts !== false ? date($format, $ts) : '—';
    }
}

<?php



declare(strict_types=1);



namespace OcMaker;



final class DocumentInventorySyncService

{

    public function __construct(

        private readonly DocumentRepository $documents = new DocumentRepository(),

        private readonly InventoryRepository $inventory = new InventoryRepository(),

        private readonly ExcelService $excel = new ExcelService(),

    ) {

    }



    /** @return array{linked:int,expected:int,networks:int,resynced:bool,message:?string,source_sheet:?string,source_rede_column:?string,source_path:?string} */

    public function ensureLinked(int $documentId, bool $force = false): array

    {

        $document = $this->documents->findById($documentId);

        if ($document === null) {

            throw new \RuntimeException('Documento não encontrado.');

        }



        $linked = $this->inventory->countByDocument($documentId);

        $networks = $this->inventory->countNetworksByDocument($documentId);

        $storedExpected = (int) ($document['total_lojas'] ?? 0);

        $adsId = trim((string) ($document['ads_id'] ?? ''));



        $sourcePath = $this->resolveSourcePath($document);

        if ($sourcePath === null) {

            $expected = $storedExpected > 0 ? $storedExpected : $linked;



            return $this->result(

                $linked,

                $expected,

                $networks,

                false,

                $linked === 0 ? $this->missingSpreadsheetMessage(0, $expected) : null,

            );

        }



        ExcelService::clearSpreadsheetCache($sourcePath);

        $campaign = $this->excel->loadCampaign($sourcePath, $adsId !== '' ? $adsId : null);

        $rows = $campaign['inventory'] ?? [];

        if ($rows === []) {

            throw new \RuntimeException('A planilha original não contém linhas de inventário para esta campanha.');

        }



        $sourceSheet = (string) ($campaign['source_sheet'] ?? 'INVENTARIO');

        $sourceRedeColumn = (string) ($campaign['source_rede_column'] ?? 'J');

        $expected = count($rows);

        $needsResync = $force

            || $linked !== $expected

            || ($storedExpected > 0 && $linked < $storedExpected);



        if (!$needsResync) {

            return $this->result(

                $linked,

                $expected,

                $networks,

                false,

                null,

                $sourceSheet,

                $sourceRedeColumn,

                $sourcePath,

            );

        }



        $this->inventory->replaceDocumentInventory($documentId, $rows);

        $this->documents->updateTotalLojas($documentId, $expected);



        $linked = $this->inventory->countByDocument($documentId);

        $networks = $this->inventory->countNetworksByDocument($documentId);

        $sourceRef = "{$sourceSheet}!{$sourceRedeColumn}:{$sourceRedeColumn}";



        $message = $linked < $expected

            ? "Re-sincronizado parcialmente ({$linked}/{$expected} unidades em {$networks} redes, lido de {$sourceRef})."

            : "Inventário re-sincronizado ({$linked} unidades em {$networks} redes, lido de {$sourceRef}).";



        return $this->result(

            $linked,

            $expected,

            $networks,

            true,

            $message,

            $sourceSheet,

            $sourceRedeColumn,

            $sourcePath,

        );

    }



    /** @param array<string, mixed> $document */

    private function resolveSourcePath(array $document): ?string

    {

        $candidates = [];

        $stored = trim((string) ($document['source_path'] ?? ''));



        if ($stored !== '') {

            $candidates[] = $stored;

            $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stored), DIRECTORY_SEPARATOR);

            $basename = basename(str_replace('\\', '/', $stored));

            foreach ($this->spreadsheetSearchDirs() as $dir) {

                $candidates[] = $dir . DIRECTORY_SEPARATOR . $basename;

            }

        }



        foreach ($candidates as $path) {

            if ($path !== '' && is_file($path)) {

                return $path;

            }

        }



        return $this->findSpreadsheetByCampaign($document);

    }



    /** @return list<string> */

    private function spreadsheetSearchDirs(): array

    {

        $root = dirname(__DIR__);



        return [

            $root . '/storage/spreadsheets',

            $root . '/storage/uploads',

        ];

    }



    /** @param array<string, mixed> $document */

    private function findSpreadsheetByCampaign(array $document): ?string

    {

        $adsId = trim((string) ($document['ads_id'] ?? ''));

        $campanha = mb_strtolower(trim((string) ($document['campanha'] ?? '')));

        if ($adsId === '' && $campanha === '') {

            return null;

        }



        foreach ($this->spreadsheetSearchDirs() as $dir) {

            if (!is_dir($dir)) {

                continue;

            }



            $files = glob($dir . '/*.xlsx') ?: [];

            foreach ($files as $file) {

                try {

                    foreach ($this->excel->listCampaigns($file) as $campaign) {

                        if ($adsId !== '' && ($campaign['ads_id'] ?? '') === $adsId) {

                            return $file;

                        }

                        if ($adsId === '' && mb_strtolower(trim((string) ($campaign['campanha'] ?? ''))) === $campanha) {

                            return $file;

                        }

                    }

                } catch (\Throwable) {

                    continue;

                }

            }

        }



        return null;

    }



    private function missingSpreadsheetMessage(int $linked, int $expected): string

    {

        if ($linked === 0) {

            return 'Nenhum item de inventário vinculado a este documento. Gere o PDF novamente enviando a planilha original.';

        }



        return "Planilha original não encontrada ({$linked}/{$expected} unidades salvas). "

            . 'Abra o documento no histórico, reenvie a planilha e gere o PDF novamente para re-sincronizar.';

    }



    /** @return array{linked:int,expected:int,networks:int,resynced:bool,message:?string,source_sheet:?string,source_rede_column:?string,source_path:?string} */

    private function result(

        int $linked,

        int $expected,

        int $networks,

        bool $resynced,

        ?string $message,

        ?string $sourceSheet = null,

        ?string $sourceRedeColumn = null,

        ?string $sourcePath = null,

    ): array {

        return [

            'linked' => $linked,

            'expected' => $expected,

            'networks' => $networks,

            'resynced' => $resynced,

            'message' => $message,

            'source_sheet' => $sourceSheet,

            'source_rede_column' => $sourceRedeColumn,

            'source_path' => $sourcePath,

        ];

    }

}



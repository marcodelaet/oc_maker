param(
    [Parameter(Mandatory = $true)][string]$PayloadPath,
    [Parameter(Mandatory = $true)][string]$TemplatePath,
    [Parameter(Mandatory = $true)][string]$OutputPdfPath
)

$ErrorActionPreference = "Stop"
$payload = Get-Content -LiteralPath $PayloadPath -Raw -Encoding UTF8 | ConvertFrom-Json

function Format-Currency([double]$Value) {
    return ("{0:C2}" -f $Value).Replace("US$", "R$")
}

function Format-Number([double]$Value) {
    return "{0:N0}" -f $Value
}

function Set-ContentControlText($doc, [string]$Tag, [string]$Text, [switch]$ClearIfEmpty) {
    if ($ClearIfEmpty -and [string]::IsNullOrWhiteSpace($Text)) {
        foreach ($cc in @($doc.ContentControls)) {
            if ($cc.Tag -eq $Tag) {
                $cc.LockContentControl = $false
                $cc.LockContents = $false
                $cc.Range.Text = ""
            }
        }
        return
    }
    foreach ($cc in @($doc.ContentControls)) {
        if ($cc.Tag -eq $Tag) {
            $cc.LockContentControl = $false
            $cc.LockContents = $false
            if ($cc.Type -eq 6 -and $Text) {
                try { $cc.Range.Text = ([datetime]$Text).ToString("dd/MM/yyyy") } catch { $cc.Range.Text = $Text }
            }
            elseif ($cc.Type -eq 4 -and $Text) {
                $cc.Range.Text = $Text
            }
            elseif ($cc.Type -eq 8) {
                if ($Text -is [bool]) { $cc.Checked = $Text }
                else { $cc.Checked = [System.Convert]::ToBoolean($Text) }
            }
            else {
                $cc.Range.Text = $Text
            }
        }
    }
}

function Hide-TableRowIfEmpty($doc, [string]$Label, [switch]$Hide) {
    if (-not $Hide) { return }
    foreach ($tbl in @($doc.Tables)) {
        for ($r = 1; $r -le $tbl.Rows.Count; $r++) {
            for ($c = 1; $c -le $tbl.Columns.Count; $c++) {
                try {
                    $txt = $tbl.Cell($r, $c).Range.Text
                    if ($txt -like "*$Label*") {
                        $tbl.Rows.Item($r).Hidden = $true
                        return
                    }
                } catch {}
            }
        }
    }
}

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
$word.AutomationSecurity = 3

try {
    $doc = $word.Documents.Open($TemplatePath, $false, $false)
    $doc.Activate()

    foreach ($cc in @($doc.ContentControls)) {
        $cc.LockContentControl = $false
        $cc.LockContents = $false
    }

    try { if ($doc.ProtectionType -ne -1) { $doc.Unprotect("") } } catch {}

    # Título e ID no corpo do documento.
    $search = $doc.Content.Find
    $search.ClearFormatting()
    $search.Replacement.ClearFormatting()
    $null = $search.Execute("#202602-0000", $false, $false, $false, $false, $false, $true, 1, $false, "#$($payload.document_id)", 2)

    if ($payload.document_title) {
        $search2 = $doc.Content.Find
        $search2.ClearFormatting()
        $search2.Replacement.ClearFormatting()
        $null = $search2.Execute("Informe de Campanha", $false, $false, $false, $false, $false, $true, 1, $false, $payload.document_title, 2)
    }

    Set-ContentControlText $doc "Anunciante" $payload.anunciante
    Set-ContentControlText $doc "Campanha" $payload.campanha
    Set-ContentControlText $doc "Data de Início" $payload.inicio
    Set-ContentControlText $doc "Término" $payload.termino
    Set-ContentControlText $doc "Tipo de Venda" $payload.tipo_venda
    Set-ContentControlText $doc "Tipo de DEAL" $payload.tipo_deal -ClearIfEmpty:([string]::IsNullOrWhiteSpace($payload.tipo_deal))
    Set-ContentControlText $doc "Planejador / SSP" $payload.planejador_ssp
    Set-ContentControlText $doc "DealID" $payload.deal_id -ClearIfEmpty:([string]::IsNullOrWhiteSpace($payload.deal_id))

    if ($payload.tipo_venda -eq "SSP") {
        Set-ContentControlText $doc "OC/Informe" $payload.oc_informe_ssp
    }
    else {
        Set-ContentControlText $doc "OC/Informe" "" -ClearIfEmpty
        Hide-TableRowIfEmpty $doc "OC/Informe (SSP)" -Hide
    }

    if ([string]::IsNullOrWhiteSpace($payload.tipo_deal)) {
        Hide-TableRowIfEmpty $doc "Tipo de DEAL" -Hide
    }
    if ([string]::IsNullOrWhiteSpace($payload.deal_id)) {
        Hide-TableRowIfEmpty $doc "Deal ID" -Hide
    }

    $inventoryTable = $doc.Tables.Item(2)
    $firstDataRow = 2
    $totalRow = 54
    $maxRows = $totalRow - $firstDataRow

    $items = @($payload.inventory)
    if ($items.Count -gt $maxRows) {
        throw "A planilha possui $($items.Count) linhas, mas o template suporta no máximo $maxRows."
    }

    for ($i = 0; $i -lt $items.Count; $i++) {
        $row = $firstDataRow + $i
        $item = $items[$i]
        $inventoryTable.Cell($row, 1).Range.Text = [string]$item.codigo
        $inventoryTable.Cell($row, 2).Range.Text = [string]$item.rede
        $inventoryTable.Cell($row, 3).Range.Text = Format-Number([double]$item.insercoes)
        $inventoryTable.Cell($row, 4).Range.Text = Format-Number([double]$item.impactos)
        $inventoryTable.Cell($row, 5).Range.Text = Format-Currency([double]$item.bruto)
        $inventoryTable.Cell($row, 6).Range.Text = Format-Currency([double]$item.liquido)
    }

    for ($row = ($firstDataRow + $items.Count); $row -lt $totalRow; $row++) {
        1..6 | ForEach-Object {
            try { $inventoryTable.Cell($row, $_).Range.Text = "" } catch {}
        }
    }

    $tot = $payload.totals
    $inventoryTable.Cell($totalRow, 2).Range.Text = Format-Number([double]$tot.insercoes)
    $inventoryTable.Cell($totalRow, 3).Range.Text = Format-Number([double]$tot.impactos)
    $inventoryTable.Cell($totalRow, 4).Range.Text = Format-Currency([double]$tot.bruto)
    $inventoryTable.Cell($totalRow, 5).Range.Text = Format-Currency([double]$tot.liquido)

    Set-ContentControlText $doc "liquidoSSP" (Format-Currency([double]$payload.valor_liquido_ssp))
    Set-ContentControlText $doc "TechFee" ("{0:N2}%" -f [double]$payload.tech_fee_percent)
    Set-ContentControlText $doc "techFeeValue" (Format-Currency([double]$payload.tech_fee_value))
    Set-ContentControlText $doc "liquidoPublisher" (Format-Currency([double]$payload.valor_liquido_publisher))
    Set-ContentControlText $doc "cpm" (Format-Currency([double]$payload.cpm_medio))

    Set-ContentControlText $doc "Checking Fotográfico?" ([string]$payload.checking_fotografico)
    Set-ContentControlText $doc "Relatórios Adicionais?" ([string]$payload.relatorios_adicionais)
    Set-ContentControlText $doc "Obs" ($payload.observacoes -join "`r`n")
    Set-ContentControlText $doc "Data de Emissão da NF" $payload.data_emissao_nf
    Set-ContentControlText $doc "Prazo para pagamento" $payload.prazo_pagamento

    $outputDir = Split-Path -Parent $OutputPdfPath
    if (-not (Test-Path $outputDir)) {
        New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
    }

    $doc.ExportAsFixedFormat($OutputPdfPath, 17)
    $doc.Close($false)
}
finally {
    $word.Quit()
    [System.Runtime.Interopservices.Marshal]::ReleaseComObject($word) | Out-Null
}

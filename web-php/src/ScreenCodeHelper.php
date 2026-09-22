<?php

declare(strict_types=1);

namespace OcMaker;

final class ScreenCodeHelper
{
    /**
     * Sufixo do código da tela a partir da denominação:
     * - com " - ": texto após o separador (ex.: SANTANA);
     * - sem " - ": denominação inteira (ex.: ÁGUA BRANCA).
     */
    public static function locationSuffixFromDenominacao(?string $denominacao): string
    {
        $denominacao = trim((string) $denominacao);
        if ($denominacao === '') {
            return '';
        }

        $pos = mb_strpos($denominacao, ' - ', 0, 'UTF-8');
        $suffix = $pos === false
            ? $denominacao
            : mb_substr($denominacao, $pos + 3, null, 'UTF-8');

        return self::normalizeLocationSuffix($suffix);
    }

    public static function normalizeLocationSuffix(string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return '';
        }

        $suffix = preg_replace('/\s+/u', ' ', $suffix) ?? $suffix;

        return mb_strtoupper($suffix, 'UTF-8');
    }

    /** Garante que o código termine com "-{suffix}" quando houver sufixo de localidade. */
    public static function ensureLocationSuffix(string $screenCode, ?string $denominacao): string
    {
        $screenCode = trim($screenCode);
        $suffix = self::locationSuffixFromDenominacao($denominacao);
        if ($suffix === '' || $screenCode === '') {
            return $screenCode;
        }

        $codeUpper = mb_strtoupper($screenCode, 'UTF-8');
        $properEnd = '-' . $suffix;

        if (str_ends_with($codeUpper, mb_strtoupper($properEnd, 'UTF-8'))) {
            return $screenCode;
        }

        $compressed = preg_replace('/\s+/u', '', $suffix) ?? $suffix;
        if ($compressed !== $suffix) {
            $compressedEnd = '-' . $compressed;
            if (str_ends_with($codeUpper, mb_strtoupper($compressedEnd, 'UTF-8'))) {
                $baseLen = mb_strlen($screenCode, 'UTF-8') - mb_strlen($compressedEnd, 'UTF-8');

                return mb_substr($screenCode, 0, $baseLen, 'UTF-8') . $properEnd;
            }
        }

        return $screenCode . $properEnd;
    }

    public static function hasLocationSuffix(string $screenCode, string $suffix): bool
    {
        $suffix = self::normalizeLocationSuffix($suffix);
        if ($suffix === '') {
            return true;
        }

        $codeUpper = mb_strtoupper(trim($screenCode), 'UTF-8');
        if (str_ends_with($codeUpper, '-' . $suffix)) {
            return true;
        }

        $compressed = preg_replace('/\s+/u', '', $suffix) ?? $suffix;

        return $compressed !== $suffix && str_ends_with($codeUpper, '-' . $compressed);
    }

    /**
     * Código padrão sugerido: {codigo}-T{NN}[-{localidade}].
     *
     * @param string|null $faceToken Ex.: T01, L01, L1 — default T + face com 2 dígitos.
     */
    public static function buildDefaultScreenCode(
        string $unitCode,
        int $faceNumber,
        ?string $denominacao,
        ?string $faceToken = null,
    ): string {
        $unitCode = trim($unitCode);
        if ($unitCode === '') {
            return '';
        }

        $faceToken = $faceToken !== null && trim($faceToken) !== ''
            ? trim($faceToken)
            : 'T' . str_pad((string) max(1, $faceNumber), 2, '0', STR_PAD_LEFT);

        return self::ensureLocationSuffix($unitCode . '-' . $faceToken, $denominacao);
    }
}

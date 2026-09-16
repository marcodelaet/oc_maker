<?php

declare(strict_types=1);

namespace OcMaker;

final class DocumentId
{
    private const ALPHANUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function generate(?\DateTimeInterface $when = null): string
    {
        $when ??= new \DateTimeImmutable('now');
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= self::ALPHANUM[random_int(0, strlen(self::ALPHANUM) - 1)];
        }
        return $when->format('Ym') . '-' . $suffix;
    }
}

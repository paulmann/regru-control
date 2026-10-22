<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Resolves the request locale.
 *
 * Priority: explicit `?lang=` (handy for debugging and shared links), saved
 * cookie, `Accept-Language` header, then English.
 */
final class LocaleNegotiator
{
    public const COOKIE = 'rg_lang';

    public function negotiate(Request $request): AppLocale
    {
        $explicit = AppLocale::fromTag($request->queryString('lang'));

        if ($explicit !== null) {
            return $explicit;
        }

        $cookie = AppLocale::fromTag($request->cookie(self::COOKIE));

        if ($cookie !== null) {
            return $cookie;
        }

        return $this->fromAcceptLanguage($request->header('accept-language'));
    }

    private function fromAcceptLanguage(?string $header): AppLocale
    {
        if ($header === null || trim($header) === '') {
            return AppLocale::default();
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', trim($part));
            $tag = trim($pieces[0]);
            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                $parameter = trim($parameter);

                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            $candidates[] = ['tag' => $tag, 'q' => $quality];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($candidates as $candidate) {
            $locale = AppLocale::fromTag($candidate['tag']);

            if ($locale !== null) {
                return $locale;
            }
        }

        return AppLocale::default();
    }
}

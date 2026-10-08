<?php

namespace App\Domain\Wiki;

use App\Models\WikiRedirect;

/**
 * One-hop wiki redirects created by a rename.
 */
final class WikiRedirects
{
    public function record(int $wikiId, string $from, string $to): void
    {
        if (strcasecmp($from, $to) === 0) {
            return;
        }
        $this->deleteTitled($wikiId, $to);
        WikiRedirect::query()
            ->where('redirects_to_wiki_id', $wikiId)
            ->whereRaw('LOWER(redirects_to) = LOWER(?)', [$from])
            ->update(['redirects_to' => $to]);
        $this->deleteTitled($wikiId, $from);
        WikiRedirect::query()->create([
            'wiki_id' => $wikiId,
            'title' => $from,
            'redirects_to' => $to,
            'redirects_to_wiki_id' => $wikiId,
            'created_on' => now(),
        ]);
    }

    /**
     * Point older redirects at the new title without storing a hop from the old title.
     */
    public function retarget(int $wikiId, string $from, string $to): void
    {
        if (strcasecmp($from, $to) === 0) {
            return;
        }
        $this->deleteTitled($wikiId, $to);
        WikiRedirect::query()
            ->where('redirects_to_wiki_id', $wikiId)
            ->whereRaw('LOWER(redirects_to) = LOWER(?)', [$from])
            ->update(['redirects_to' => $to]);
    }

    public function target(int $wikiId, string $title): ?string
    {
        $redirect = WikiRedirect::query()
            ->where('wiki_id', $wikiId)
            ->whereRaw('LOWER(title) = LOWER(?)', [$title])
            ->orderBy('id')
            ->first();
        if (! $redirect instanceof WikiRedirect) {
            return null;
        }
        $target = $redirect->redirects_to;

        return is_string($target) && $target !== '' ? $target : null;
    }

    public function forget(int $wikiId, string $title): void
    {
        $this->deleteTitled($wikiId, $title);
        WikiRedirect::query()
            ->where('redirects_to_wiki_id', $wikiId)
            ->whereRaw('LOWER(redirects_to) = LOWER(?)', [$title])
            ->delete();
    }

    /**
     * @return list<array{title: string, redirects_to: string, redirects_to_wiki_id: int}>
     */
    public function listed(int $wikiId): array
    {
        $rows = [];
        foreach (WikiRedirect::query()->where('wiki_id', $wikiId)->orderBy('title')->orderBy('id')->get() as $redirect) {
            $title = $redirect->title;
            $target = $redirect->redirects_to;
            if (! is_string($title) || ! is_string($target)) {
                continue;
            }
            $rows[] = [
                'title' => $title,
                'redirects_to' => $target,
                'redirects_to_wiki_id' => (int) $redirect->redirects_to_wiki_id,
            ];
        }

        return $rows;
    }

    private function deleteTitled(int $wikiId, string $title): void
    {
        WikiRedirect::query()
            ->where('wiki_id', $wikiId)
            ->whereRaw('LOWER(title) = LOWER(?)', [$title])
            ->delete();
    }
}

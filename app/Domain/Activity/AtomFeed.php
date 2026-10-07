<?php

namespace App\Domain\Activity;

use App\Domain\Notifications\MailIdentity;
use App\Models\User;

/**
 * Atom documents for activity and issue lists.
 */
final class AtomFeed
{
    public function __construct(
        private readonly MailIdentity $identity,
    ) {}

    /**
     * @param  list<ActivityEvent>  $events
     */
    public function render(string $title, User $actor, array $events): string
    {
        $host = $this->identity->host();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<feed xmlns="http://www.w3.org/2005/Atom">',
            '  <title>'.$this->escape($title).'</title>',
            '  <author><name>'.$this->escape((string) $actor->login).'</name></author>',
        ];
        foreach ($events as $event) {
            $id = 'tag:'.$host.','.substr($event->at, 0, 10).':'.$event->kind.'/'.$event->id;
            $lines[] = '  <entry>';
            $lines[] = '    <id>'.$this->escape($id).'</id>';
            $lines[] = '    <title>'.$this->escape($event->title).'</title>';
            $lines[] = '    <updated>'.$this->escape($this->updated($event->at)).'</updated>';
            $lines[] = '    <author><name>'.$this->escape($event->author).'</name></author>';
            $lines[] = '    <category term="'.$this->escape($event->kind).'"/>';
            $lines[] = '  </entry>';
        }
        $lines[] = '</feed>';

        return implode("\n", $lines)."\n";
    }

    private function updated(string $at): string
    {
        return str_replace(' ', 'T', $at).'Z';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

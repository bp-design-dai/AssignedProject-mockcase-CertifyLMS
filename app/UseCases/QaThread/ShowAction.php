<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

final class ShowAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        $thread->load(['user', 'certification', 'replies.user'])->loadCount('replies');

        foreach ($thread->replies as $reply) {
            $reply->setRelation('thread', $thread);
        }

        return $thread;
    }
}

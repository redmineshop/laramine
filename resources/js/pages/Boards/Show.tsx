import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type TopicRow = {
    id: number;
    subject: string;
    sticky: number;
    locked: boolean;
    replies_count: number;
    updated_on: string;
};

type BoardsShowProps = {
    project: string;
    board: {
        id: number;
        name: string;
        description: string | null;
        topics_count: number;
        messages_count: number;
    };
    topics: TopicRow[];
    page: number;
    pages: number;
    perPage: number;
    total: number;
    sort: string;
    canManage: boolean;
    canPost: boolean;
};

export default function BoardsShow({
    project,
    board,
    topics,
    page,
    pages,
    perPage,
    total,
    sort,
    canManage,
    canPost,
}: BoardsShowProps) {
    const base = `/projects/${project}/boards/${board.id}`;

    return (
        <>
            <Head title={board.name} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{board.name}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Functional topic list. This is not a Redmine screen and not a 0.1 release. {total} topics,
                            page {page} of {pages}, {perPage} per page.
                            {sort === '' ? '' : ` Sort ${sort}.`}
                        </p>
                        {board.description === null ? null : <p>{board.description}</p>}
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="secondary">
                                <Link href={`/projects/${project}/boards`}>All boards</Link>
                            </Button>
                            {canPost ? (
                                <Button asChild>
                                    <Link href={`/boards/${board.id}/topics/new`}>New topic</Link>
                                </Button>
                            ) : null}
                            {canManage ? (
                                <Button asChild variant="secondary">
                                    <Link href={`${base}/edit`}>Edit board</Link>
                                </Button>
                            ) : null}
                            {canManage ? (
                                <Button variant="secondary" onClick={() => router.delete(base)}>
                                    Delete board
                                </Button>
                            ) : null}
                        </div>
                        <ul className="flex flex-col gap-2">
                            {topics.map((topic) => (
                                <li key={topic.id} className="flex flex-wrap items-center gap-2 text-sm">
                                    <Link
                                        href={`/boards/${board.id}/topics/${topic.id}`}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {topic.subject}
                                    </Link>
                                    {topic.sticky === 1 ? <Badge variant="secondary">sticky</Badge> : null}
                                    {topic.locked ? <Badge variant="secondary">locked</Badge> : null}
                                    <span className="text-muted-foreground">
                                        {topic.replies_count} replies, {topic.updated_on}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

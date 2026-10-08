import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type BoardRow = {
    id: number;
    name: string;
    description: string | null;
    parent_id: number | null;
    topics_count: number;
    messages_count: number;
    depth: number;
};

type BoardsIndexProps = {
    project: string;
    boards: BoardRow[];
    canManage: boolean;
};

export default function BoardsIndex({ project, boards, canManage }: BoardsIndexProps) {
    return (
        <>
            <Head title="Boards" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Boards</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Functional board list. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        {canManage ? (
                            <Button asChild variant="secondary" className="w-fit">
                                <Link href={`/projects/${project}/boards/new`}>New board</Link>
                            </Button>
                        ) : null}
                        <ul className="flex flex-col gap-2">
                            {boards.map((board) => (
                                <li key={board.id} style={{ marginLeft: `${board.depth * 1.25}rem` }} className="text-sm">
                                    <Link
                                        href={`/projects/${project}/boards/${board.id}`}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {board.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        {board.topics_count} topics, {board.messages_count} messages
                                    </span>
                                    {board.description === null ? null : (
                                        <p className="text-muted-foreground">{board.description}</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

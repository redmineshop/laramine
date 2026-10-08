import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ReplyRow = {
    id: number;
    subject: string;
    author_id: number | null;
    content_html: string;
    canEdit: boolean;
    canDelete: boolean;
};

type MessagesShowProps = {
    project: string;
    board: { id: number; name: string };
    topic: {
        id: number;
        subject: string;
        content: string;
        content_html: string;
        sticky: number;
        locked: boolean;
        replies_count: number;
    };
    replies: ReplyRow[];
    page: number;
    pages: number;
    perPage: number;
    total: number;
    attachments: { id: number; filename: string }[];
    watching: boolean;
    watcherIds: number[] | null;
    canReply: boolean;
    canEdit: boolean;
    canDelete: boolean;
    canModerate: boolean;
};

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    const value = match?.[1];

    return value === undefined ? '' : decodeURIComponent(value);
}

export default function MessagesShow({
    project,
    board,
    topic,
    replies,
    page,
    pages,
    total,
    attachments,
    watching,
    watcherIds,
    canReply,
    canEdit,
    canDelete,
    canModerate,
}: MessagesShowProps) {
    const path = `/boards/${board.id}/topics/${topic.id}`;
    const reply = useForm({ subject: '', content: '' });
    const watcher = useForm({ user_id: '' });

    function submitReply(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        reply.post(`${path}/replies`);
    }

    async function onFile(file: File): Promise<void> {
        const uploaded = await fetch(
            `/attachments/upload?filename=${encodeURIComponent(file.name)}&content_type=${encodeURIComponent(file.type)}`,
            {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': csrfToken(),
                    'Content-Type': file.type === '' ? 'application/octet-stream' : file.type,
                },
                body: file,
            },
        );
        if (!uploaded.ok) {
            return;
        }
        const payload: unknown = await uploaded.json();
        if (typeof payload !== 'object' || payload === null || !('token' in payload) || typeof payload.token !== 'string') {
            return;
        }
        router.post(`${path}/attachments`, { token: payload.token, filename: file.name });
    }

    return (
        <>
            <Head title={topic.subject} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{topic.subject}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Functional topic. This is not a Redmine screen and not a 0.1 release. {total} replies, page{' '}
                            {page} of {pages}.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {topic.sticky === 1 ? <Badge variant="secondary">sticky</Badge> : null}
                            {topic.locked ? <Badge variant="secondary">locked</Badge> : null}
                            <Button asChild variant="secondary">
                                <Link href={`/projects/${project}/boards/${board.id}`}>{board.name}</Link>
                            </Button>
                            {canEdit ? (
                                <Button asChild variant="secondary">
                                    <Link href={`${path}/edit`}>Edit</Link>
                                </Button>
                            ) : null}
                            {canReply ? (
                                <Button asChild variant="secondary">
                                    <Link href={`${path}/quote`}>Quote</Link>
                                </Button>
                            ) : null}
                            {canDelete ? (
                                <Button variant="secondary" onClick={() => router.delete(path)}>
                                    Delete
                                </Button>
                            ) : null}
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    router.post(watching ? '/watchers/unwatch' : '/watchers/watch', {
                                        object_type: 'message',
                                        object_id: topic.id,
                                    })
                                }
                            >
                                {watching ? 'Unwatch' : 'Watch'}
                            </Button>
                        </div>
                        <div className="wiki text-sm" dangerouslySetInnerHTML={{ __html: topic.content_html }} />
                        <ul className="text-sm">
                            {attachments.map((file) => (
                                <li key={file.id}>
                                    <a href={`/attachments/${file.id}`}>{file.filename}</a>
                                </li>
                            ))}
                        </ul>
                        <Label htmlFor="message-file">Attachment</Label>
                        <Input
                            id="message-file"
                            type="file"
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                if (file !== undefined) {
                                    void onFile(file);
                                }
                            }}
                        />
                        {watcherIds === null ? null : (
                            <form
                                className="flex items-end gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    watcher.transform((data) => ({
                                        object_type: 'message',
                                        object_id: topic.id,
                                        user_id: Number(data.user_id),
                                    }));
                                    watcher.post('/watchers');
                                }}
                            >
                                <div>
                                    <Label htmlFor="user_id">Watchers</Label>
                                    <Input
                                        id="user_id"
                                        value={watcher.data.user_id}
                                        onChange={(event) => watcher.setData('user_id', event.target.value)}
                                    />
                                    <p className="text-xs text-muted-foreground">{watcherIds.join(', ')}</p>
                                </div>
                                {canModerate ? <Button type="submit">Add watcher</Button> : null}
                            </form>
                        )}
                    </CardContent>
                </Card>
                <ul className="flex flex-col gap-3">
                    {replies.map((row) => (
                        <li key={row.id} id={`message-${row.id}`}>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">{row.subject}</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2">
                                    <div className="wiki text-sm" dangerouslySetInnerHTML={{ __html: row.content_html }} />
                                    <div className="flex gap-2">
                                        {row.canEdit ? (
                                            <Button asChild variant="secondary">
                                                <Link href={`/boards/${board.id}/topics/${row.id}/edit`}>Edit</Link>
                                            </Button>
                                        ) : null}
                                        {row.canDelete ? (
                                            <Button
                                                variant="secondary"
                                                onClick={() => router.delete(`/boards/${board.id}/topics/${row.id}`)}
                                            >
                                                Delete
                                            </Button>
                                        ) : null}
                                    </div>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
                {canReply ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Reply</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="flex flex-col gap-3" onSubmit={submitReply}>
                                <Label htmlFor="subject">Subject</Label>
                                <Input
                                    id="subject"
                                    value={reply.data.subject}
                                    onChange={(event) => reply.setData('subject', event.target.value)}
                                />
                                <Label htmlFor="content">Content</Label>
                                <textarea
                                    id="content"
                                    className="border-input min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                    value={reply.data.content}
                                    onChange={(event) => reply.setData('content', event.target.value)}
                                />
                                <Button type="submit" disabled={reply.processing}>
                                    Post reply
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </main>
        </>
    );
}

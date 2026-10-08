import { Head, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

function namedError(errors: object, name: string): string | null {
    if (!(name in errors)) {
        return null;
    }
    const value: unknown = Reflect.get(errors, name);

    return typeof value === 'string' ? value : null;
}

type MessagesEditProps = {
    mode: 'new' | 'edit' | 'quote';
    project: string;
    boardId: number;
    messageId: number | null;
    subject: string;
    content: string;
    locked: boolean;
    sticky: boolean;
    quotedId: number | null;
    canModerate: boolean;
};

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    const value = match?.[1];

    return value === undefined ? '' : decodeURIComponent(value);
}

export default function MessagesEdit({
    mode,
    boardId,
    messageId,
    subject,
    content,
    locked,
    sticky,
    quotedId,
    canModerate,
}: MessagesEditProps) {
    const form = useForm({
        subject,
        content,
        locked,
        sticky,
    });
    const [previewHtml, setPreviewHtml] = useState('');

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (mode === 'quote' && messageId !== null) {
            form.post(`/boards/${boardId}/topics/${messageId}/replies`);

            return;
        }
        if (mode === 'edit' && messageId !== null) {
            form.put(`/boards/${boardId}/topics/${messageId}`);

            return;
        }
        form.post(`/boards/${boardId}/topics`);
    }

    async function preview(): Promise<void> {
        const target = messageId === null ? `/boards/${boardId}/topics/preview` : `/boards/${boardId}/topics/${messageId}/preview`;
        const response = await fetch(target, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/html',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ content: form.data.content }),
        });
        setPreviewHtml(await response.text());
    }

    const title = mode === 'new' ? 'New topic' : mode === 'quote' ? 'Quote' : 'Edit message';

    return (
        <>
            <Head title={title} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {title}
                            {quotedId === null ? '' : ` #${quotedId}`}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-3" onSubmit={submit}>
                            <p className="text-sm text-muted-foreground">
                                Functional message form. This is not a Redmine screen and not a 0.1 release.
                            </p>
                            <Label htmlFor="subject">Subject</Label>
                            <Input
                                id="subject"
                                value={form.data.subject}
                                onChange={(event) => form.setData('subject', event.target.value)}
                            />
                            <Label htmlFor="content">Content</Label>
                            <textarea
                                id="content"
                                className="border-input min-h-40 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                value={form.data.content}
                                onChange={(event) => form.setData('content', event.target.value)}
                            />
                            {canModerate && mode !== 'quote' ? (
                                <>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={form.data.sticky}
                                            onChange={(event) => form.setData('sticky', event.target.checked)}
                                        />
                                        Sticky
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={form.data.locked}
                                            onChange={(event) => form.setData('locked', event.target.checked)}
                                        />
                                        Locked
                                    </label>
                                </>
                            ) : null}
                            <div className="flex gap-2">
                                <Button type="submit" disabled={form.processing}>
                                    Save
                                </Button>
                                <Button type="button" variant="secondary" onClick={() => void preview()}>
                                    Preview
                                </Button>
                            </div>
                            {namedError(form.errors, 'form') === null ? null : (
                                <p className="text-sm text-destructive">{namedError(form.errors, 'form')}</p>
                            )}
                        </form>
                        <div className="wiki mt-4 text-sm" dangerouslySetInnerHTML={{ __html: previewHtml }} />
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

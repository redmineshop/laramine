import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WikiShowProps = {
    project: string;
    page: {
        id: number;
        title: string;
        slug: string;
        version: number;
        currentVersion: number;
        protected: boolean;
        parent_id: number | null;
        text: string;
    };
    html: string;
    attachments: { id: number; filename: string }[];
    watching: boolean;
    watcherIds: number[] | null;
    canEdit: boolean;
    canRename: boolean;
    canDelete: boolean;
    canProtect: boolean;
    canExport: boolean;
    canHistory: boolean;
    canManage: boolean;
};

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    const value = match?.[1];

    return value === undefined ? '' : decodeURIComponent(value);
}

export default function WikiShow({
    project,
    page,
    html,
    attachments,
    watching,
    watcherIds,
    canEdit,
    canRename,
    canDelete,
    canProtect,
    canExport,
    canHistory,
    canManage,
}: WikiShowProps) {
    const base = `/projects/${project}/wiki/${page.slug}`;
    const settings = useForm({ start_page: page.title });
    const watcher = useForm({ user_id: '' });

    function onSettings(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        settings.put(`/projects/${project}/wiki`);
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
        router.post(`${base}/add_attachment`, { token: payload.token, filename: file.name });
    }

    return (
        <>
            <Head title={page.title} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            {page.title}
                            {page.protected ? <Badge variant="secondary">protected</Badge> : null}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Functional wiki page. This is not a Redmine screen and not a 0.1 release. Version {page.version}
                            {page.version === page.currentVersion ? '' : ` of ${page.currentVersion}`}.
                        </p>
                        <div className="flex flex-wrap gap-3 text-sm">
                            <Link href={`/projects/${project}/wiki/index`} className="underline-offset-4 hover:underline">
                                Index
                            </Link>
                            <Link href={`/projects/${project}/wiki/date_index`} className="underline-offset-4 hover:underline">
                                By date
                            </Link>
                            {canEdit ? (
                                <Link href={`${base}/edit`} className="underline-offset-4 hover:underline">
                                    Edit
                                </Link>
                            ) : null}
                            {canHistory ? (
                                <>
                                    <Link href={`${base}/history`} className="underline-offset-4 hover:underline">
                                        History
                                    </Link>
                                    <Link href={`${base}/diff`} className="underline-offset-4 hover:underline">
                                        Diff
                                    </Link>
                                    <Link href={`${base}/annotate`} className="underline-offset-4 hover:underline">
                                        Annotate
                                    </Link>
                                </>
                            ) : null}
                            {canRename ? (
                                <Link href={`${base}/rename`} className="underline-offset-4 hover:underline">
                                    Rename
                                </Link>
                            ) : null}
                            {canExport ? (
                                <>
                                    <a href={`${base}.txt`}>Text</a>
                                    <a href={`${base}.html`}>HTML</a>
                                    <a href={`${base}.pdf`}>PDF</a>
                                </>
                            ) : null}
                        </div>
                        <div className="wiki text-sm" dangerouslySetInnerHTML={{ __html: html }} />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    watching
                                        ? router.delete('/watchers/unwatch', {
                                              data: { object_type: 'wiki_page', object_id: page.id },
                                          })
                                        : router.post('/watchers/watch', { object_type: 'wiki_page', object_id: page.id })
                                }
                            >
                                {watching ? 'Unwatch' : 'Watch'}
                            </Button>
                            {canProtect ? (
                                <Button type="button" variant="secondary" onClick={() => router.post(`${base}/protect`)}>
                                    {page.protected ? 'Unprotect' : 'Protect'}
                                </Button>
                            ) : null}
                            {canDelete ? (
                                <Button type="button" variant="secondary" onClick={() => router.delete(base)}>
                                    Delete
                                </Button>
                            ) : null}
                        </div>
                        {watcherIds === null ? null : (
                            <p className="text-sm">Watchers: {watcherIds.length === 0 ? 'none' : watcherIds.join(', ')}</p>
                        )}
                        {canEdit ? (
                            <form
                                className="flex flex-col gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    const data = new FormData(event.currentTarget);
                                    const userId = String(data.get('user_id') ?? '');
                                    if (userId !== '') {
                                        watcher.transform(() => ({
                                            object_type: 'wiki_page',
                                            object_id: page.id,
                                            user_id: userId,
                                        }));
                                        watcher.post('/watchers');
                                    }
                                }}
                            >
                                <Label htmlFor="watcher-user">Add watcher</Label>
                                <Input
                                    id="watcher-user"
                                    name="user_id"
                                    value={watcher.data.user_id}
                                    onChange={(event) => watcher.setData('user_id', event.target.value)}
                                />
                                <Button type="submit" variant="secondary">
                                    Add watcher
                                </Button>
                            </form>
                        ) : null}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Attachments</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {attachments.length === 0 ? <p className="text-sm">No attachments.</p> : null}
                        <ul className="text-sm">
                            {attachments.map((file) => (
                                <li key={file.id}>
                                    <a href={`/attachments/${file.id}`}>{file.filename}</a>
                                </li>
                            ))}
                        </ul>
                        {canEdit ? (
                            <Input
                                type="file"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (file !== undefined) {
                                        void onFile(file);
                                    }
                                }}
                            />
                        ) : null}
                    </CardContent>
                </Card>
                {canManage ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Wiki</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="flex flex-col gap-2" onSubmit={onSettings}>
                                <Label htmlFor="start_page">Start page</Label>
                                <Input
                                    id="start_page"
                                    value={settings.data.start_page}
                                    onChange={(event) => settings.setData('start_page', event.target.value)}
                                />
                                <div className="flex gap-2">
                                    <Button type="submit">Save start page</Button>
                                    <Button type="button" variant="secondary" onClick={() => router.delete(`/projects/${project}/wiki`)}>
                                        Delete wiki
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </main>
        </>
    );
}

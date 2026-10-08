import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
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

type WikiEditProps = {
    mode: 'new' | 'edit' | 'section';
    project: string;
    title: string;
    slug: string;
    pageId: number | null;
    text: string;
    comments: string;
    version: number | null;
    section: number | null;
    sectionHash: string | null;
    parentId: number | null;
    protected: boolean;
    canProtect: boolean;
    pages: { id: number; title: string }[];
    conflict: string | null;
};

export default function WikiEdit({
    mode,
    project,
    title,
    slug,
    text,
    comments,
    version,
    section,
    sectionHash,
    parentId,
    canProtect,
    pages,
    conflict,
}: WikiEditProps) {
    const form = useForm({
        title,
        text,
        comments,
        version: version === null ? '' : String(version),
        section: section === null ? '' : String(section),
        section_hash: sectionHash ?? '',
        parent_id: parentId === null ? '' : String(parentId),
        protected: false,
    });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (mode === 'new') {
            form.post(`/projects/${project}/wiki/new`);

            return;
        }
        form.transform((data) => ({
            ...data,
            version: data.version === '' ? undefined : Number(data.version),
            section: data.section === '' ? undefined : Number(data.section),
            section_hash: data.section_hash === '' ? undefined : data.section_hash,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
        }));
        form.put(`/projects/${project}/wiki/${slug}`);
    }

    async function preview(): Promise<void> {
        const target = mode === 'new' ? 'Wiki' : slug;
        const response = await fetch(`/projects/${project}/wiki/${target}/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/html',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ text: form.data.text }),
        });
        const body = await response.text();
        const node = document.getElementById('wiki-preview');
        if (node !== null) {
            node.innerHTML = body;
        }
    }

    return (
        <>
            <Head title={mode === 'new' ? 'New wiki page' : `Edit ${title}`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{mode === 'section' ? `Edit section ${section ?? ''} of ${title}` : mode === 'new' ? 'New wiki page' : `Edit ${title}`}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-3" onSubmit={submit}>
                            {conflict === null ? null : <p className="text-sm text-destructive">{conflict}</p>}
                            <p className="text-sm text-muted-foreground">
                                Functional wiki editor. This is not a Redmine screen and not a 0.1 release.
                            </p>
                            {mode === 'new' ? (
                                <>
                                    <Label htmlFor="title">Title</Label>
                                    <Input id="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                                </>
                            ) : null}
                            <Label htmlFor="text">Text</Label>
                            <textarea
                                id="text"
                                className="border-input min-h-40 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                value={form.data.text}
                                onChange={(event) => form.setData('text', event.target.value)}
                            />
                            <Label htmlFor="comments">Comment</Label>
                            <Input id="comments" value={form.data.comments} onChange={(event) => form.setData('comments', event.target.value)} />
                            <Label htmlFor="parent_id">Parent</Label>
                            <select
                                id="parent_id"
                                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                value={form.data.parent_id}
                                onChange={(event) => form.setData('parent_id', event.target.value)}
                            >
                                <option value="">None</option>
                                {pages.map((page) => (
                                    <option key={page.id} value={page.id}>
                                        {page.title}
                                    </option>
                                ))}
                            </select>
                            {canProtect && mode === 'new' ? (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.protected}
                                        onChange={(event) => form.setData('protected', event.target.checked)}
                                    />
                                    Protected
                                </label>
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
                        <div id="wiki-preview" className="wiki mt-4 text-sm" />
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

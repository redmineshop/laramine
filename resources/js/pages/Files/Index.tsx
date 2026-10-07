import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

type FileRow = {
    id: number;
    filename: string;
    filesize: number;
    downloads: number;
};

type FileContainer = {
    container_type: string;
    container_id: number;
    files: FileRow[];
};

type VersionOption = {
    id: number;
    name: string;
};

type FilesIndexProps = {
    projectId: number;
    sortBy: string;
    containers: FileContainer[];
    versions: VersionOption[];
    canManage: boolean;
};

const sorts = ['filename', 'created_on', 'size', 'downloads'] as const;

export default function FilesIndex({ projectId, sortBy, containers, versions, canManage }: FilesIndexProps) {
    const form = useForm<{ version_id: string; file: File | null }>({
        version_id: '',
        file: null,
    });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.post(`/projects/${projectId}/files`, { forceFormData: true });
    }

    return (
        <>
            <Head title="Files" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Files</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal file list. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        <p className="flex flex-wrap gap-3 text-sm">
                            {sorts.map((sort) => (
                                <Link
                                    key={sort}
                                    href={`/projects/${projectId}/files?sort_by=${sort}`}
                                    className={sort === sortBy ? 'font-medium underline' : 'underline-offset-4 hover:underline'}
                                >
                                    {sort}
                                </Link>
                            ))}
                        </p>
                        {containers.map((container) => (
                            <section key={`${container.container_type}-${container.container_id}`}>
                                <h2 className="text-sm font-medium">
                                    {container.container_type} {container.container_id}
                                </h2>
                                {container.files.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No files.</p>
                                ) : (
                                    <ul className="flex flex-col gap-1">
                                        {container.files.map((file) => (
                                            <li key={file.id} className="text-sm">
                                                <a
                                                    href={`/projects/${projectId}/files/${file.id}/download`}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {file.filename}
                                                </a>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {file.filesize} bytes · {file.downloads} downloads
                                                </span>
                                                {canManage ? (
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        className="ml-2"
                                                        onClick={() =>
                                                            router.delete(`/projects/${projectId}/files/${file.id}`)
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        ))}
                    </CardContent>
                </Card>
                {canManage ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add file</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="flex flex-col gap-3" onSubmit={submit}>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="version_id">Version</Label>
                                    <select
                                        id="version_id"
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        value={form.data.version_id}
                                        onChange={(event) => form.setData('version_id', event.target.value)}
                                    >
                                        <option value="">Project</option>
                                        {versions.map((version) => (
                                            <option key={version.id} value={version.id}>
                                                {version.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="file">File</Label>
                                    <input
                                        id="file"
                                        type="file"
                                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    Upload
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </main>
        </>
    );
}

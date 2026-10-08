import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type WikiHistoryProps = {
    project: string;
    title: string;
    slug: string;
    versions: { version: number; author_id: number | null; comments: string; updated_on: string }[];
    canDelete: boolean;
};

export default function WikiHistory({ project, title, slug, versions, canDelete }: WikiHistoryProps) {
    const base = `/projects/${project}/wiki/${slug}`;

    return (
        <>
            <Head title={`${title} history`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{title} history</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        <Link href={base} className="text-sm underline-offset-4 hover:underline">
                            Page
                        </Link>
                        <ul className="flex flex-col gap-2 text-sm">
                            {versions.map((row) => (
                                <li key={row.version} className="flex flex-wrap items-center gap-2">
                                    <Link href={`${base}/${row.version}`} className="underline-offset-4 hover:underline">
                                        Version {row.version}
                                    </Link>
                                    <span className="text-muted-foreground">{row.updated_on}</span>
                                    <span>{row.comments}</span>
                                    {canDelete ? (
                                        <Button type="button" variant="secondary" onClick={() => router.delete(`${base}/${row.version}`)}>
                                            Delete version
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

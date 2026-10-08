import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type WikiRow = {
    id: number;
    title: string;
    slug: string;
    parent_id: number | null;
    protected: boolean;
    version: number;
    updated_on: string;
};

type WikiIndexProps = {
    project: string;
    mode: 'index' | 'date_index';
    pages: WikiRow[];
    groups: { date: string; pages: WikiRow[] }[];
    canEdit: boolean;
    canManage: boolean;
};

function PageLink({ project, page }: { project: string; page: WikiRow }) {
    return (
        <li className="flex items-center gap-2 text-sm">
            <Link href={`/projects/${project}/wiki/${page.slug}`} className="underline-offset-4 hover:underline">
                {page.title}
            </Link>
            <span className="text-muted-foreground">v{page.version}</span>
            {page.protected ? <Badge variant="secondary">protected</Badge> : null}
            <span className="text-muted-foreground">{page.updated_on}</span>
        </li>
    );
}

export default function WikiIndex({ project, mode, pages, groups, canEdit, canManage }: WikiIndexProps) {
    const rows = mode === 'date_index' ? groups.flatMap((group) => group.pages) : pages;

    return (
        <>
            <Head title={mode === 'date_index' ? 'Wiki by date' : 'Wiki index'} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{mode === 'date_index' ? 'Wiki by date' : 'Wiki index'}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Functional wiki index. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        <div className="flex gap-3 text-sm">
                            <Link href={`/projects/${project}/wiki/index`} className="underline-offset-4 hover:underline">
                                Index
                            </Link>
                            <Link href={`/projects/${project}/wiki/date_index`} className="underline-offset-4 hover:underline">
                                By date
                            </Link>
                            <Link href={`/projects/${project}/wiki`} className="underline-offset-4 hover:underline">
                                Start page
                            </Link>
                            {canEdit ? (
                                <Link href={`/projects/${project}/wiki/new`} className="underline-offset-4 hover:underline">
                                    New page
                                </Link>
                            ) : null}
                        </div>
                        {canManage ? <p className="text-sm">Wiki settings are available on the start page.</p> : null}
                        {mode === 'date_index' ? (
                            groups.map((group) => (
                                <section key={group.date} className="flex flex-col gap-1">
                                    <h2 className="text-sm font-medium">{group.date}</h2>
                                    <ul className="flex flex-col gap-1">
                                        {group.pages.map((page) => (
                                            <PageLink key={page.id} project={project} page={page} />
                                        ))}
                                    </ul>
                                </section>
                            ))
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {rows.map((page) => (
                                    <PageLink key={page.id} project={project} page={page} />
                                ))}
                            </ul>
                        )}
                        {rows.length === 0 ? <p className="text-sm">No pages.</p> : null}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

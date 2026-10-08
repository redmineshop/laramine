import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type WikiAnnotateProps = {
    project: string;
    title: string;
    slug: string;
    version: number | null;
    lines: { line: string; version: number; author_id: number | null }[];
};

export default function WikiAnnotate({ project, title, slug, version, lines }: WikiAnnotateProps) {
    return (
        <>
            <Head title={`${title} annotate`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {title} annotate{version === null ? '' : ` ${version}`}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        <Link href={`/projects/${project}/wiki/${slug}`} className="text-sm underline-offset-4 hover:underline">
                            Page
                        </Link>
                        <ul className="font-mono text-sm">
                            {lines.map((row, index) => (
                                <li key={`${row.version}-${index}`}>
                                    v{row.version} {row.line}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type WikiDiffProps = {
    project: string;
    title: string;
    slug: string;
    from: number;
    to: number;
    ops: { op: string; text: string }[];
};

export default function WikiDiff({ project, title, slug, from, to, ops }: WikiDiffProps) {
    return (
        <>
            <Head title={`${title} diff`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {title} diff {from} to {to}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        <Link href={`/projects/${project}/wiki/${slug}`} className="text-sm underline-offset-4 hover:underline">
                            Page
                        </Link>
                        <ul className="font-mono text-sm">
                            {ops.map((op, index) => (
                                <li key={`${op.op}-${index}`}>
                                    {op.op === 'insert' ? '+ ' : op.op === 'delete' ? '- ' : '  '}
                                    {op.text}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

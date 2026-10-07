import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type GanttRow = {
    kind: string;
    id: number;
    indent: number;
    name: string;
};

type GanttShowProps = {
    projectId: number | null;
    year: number;
    month: number;
    months: number;
    zoom: number;
    truncated: boolean;
    png: string;
    rows: GanttRow[];
};

export default function GanttShow({ projectId, year, month, months, zoom, truncated, png, rows }: GanttShowProps) {
    const pdf =
        projectId === null
            ? `/issues/gantt.pdf?year=${year}&month=${month}&months=${months}&zoom=${zoom}`
            : `/projects/${projectId}/issues/gantt.pdf?year=${year}&month=${month}&months=${months}&zoom=${zoom}`;

    return (
        <>
            <Head title="Gantt" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Gantt {year}-{month}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal row list. This is not a Redmine screen and not a 0.1 release. PNG export is {png}.
                            {truncated ? ' The chart is truncated.' : ''}
                        </p>
                        <a href={pdf} className="text-sm underline-offset-4 hover:underline">
                            PDF
                        </a>
                        {rows.length === 0 ? (
                            <p className="text-sm">No rows.</p>
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {rows.map((row) => (
                                    <li key={`${row.kind}-${row.id}`} style={{ paddingInlineStart: `${row.indent}rem` }}>
                                        {row.kind} {row.id}: {row.name}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

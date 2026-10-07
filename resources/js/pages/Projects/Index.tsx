import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type ProjectRow = Record<string, string | null>;

type ProjectIndexProps = {
    columns: string[];
    rows: ProjectRow[];
};

export default function ProjectIndex({ columns, rows }: ProjectIndexProps) {
    return (
        <>
            <Head title="Projects" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Projects</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Active projects from the project query. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        {rows.length === 0 ? (
                            <p className="text-sm">No projects.</p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {rows.map((row, index) => (
                                    <li key={row.identifier ?? index}>
                                        {columns.map((column) => (
                                            <span key={column} className="mr-3 text-sm">
                                                {row[column] ?? ''}
                                            </span>
                                        ))}
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

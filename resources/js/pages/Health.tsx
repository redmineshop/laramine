import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type HealthPageProps = {
    status: string;
};

export default function Health({ status }: HealthPageProps) {
    return (
        <>
            <Head title="Health" />
            <main className="flex min-h-svh items-center justify-center bg-background p-6 text-foreground">
                <Card className="w-full max-w-lg">
                    <CardHeader>
                        <CardTitle>Laramine</CardTitle>
                        <CardDescription>
                            Frontend scaffold mounted. This is not the product UI.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <Badge variant="secondary">{status}</Badge>
                        <p className="text-sm text-muted-foreground">
                            Inertia, React, and TypeScript booted from the Vite
                            entry. Redmine UX parity is not claimed. This is not
                            a 0.1 release.
                        </p>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type NewsRow = {
    id: number;
    project_id: number;
    title: string;
    summary: string;
    author_id: number;
    comments_count: number;
};

type NewsIndexProps = {
    scope: 'all' | 'project';
    projectId: number | null;
    news: NewsRow[];
    canManage: boolean;
};

export default function NewsIndex({ scope, projectId, news, canManage }: NewsIndexProps) {
    const form = useForm({
        title: '',
        summary: '',
        description: '',
    });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (projectId === null) {
            return;
        }
        form.post(`/projects/${projectId}/news`);
    }

    return (
        <>
            <Head title="News" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{scope === 'project' ? 'Project news' : 'News'}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal list. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        {news.length === 0 ? (
                            <p className="text-sm">No news.</p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {news.map((item) => (
                                    <li key={item.id}>
                                        <Link href={`/news/${item.id}`} className="underline-offset-4 hover:underline">
                                            {item.title}
                                        </Link>
                                        <span className="text-sm text-muted-foreground">
                                            {' '}
                                            #{item.id} · project {item.project_id} · {item.comments_count} comments
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
                {canManage && projectId !== null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add news</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="flex flex-col gap-3" onSubmit={submit}>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        value={form.data.title}
                                        onChange={(event) => form.setData('title', event.target.value)}
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="summary">Summary</Label>
                                    <Input
                                        id="summary"
                                        value={form.data.summary}
                                        onChange={(event) => form.setData('summary', event.target.value)}
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="description">Description</Label>
                                    <Input
                                        id="description"
                                        value={form.data.description}
                                        onChange={(event) => form.setData('description', event.target.value)}
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    Save
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </main>
        </>
    );
}

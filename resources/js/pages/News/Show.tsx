import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type CommentRow = {
    id: number;
    author_id: number;
    content: string;
    content_html: string;
};

type NewsShowProps = {
    news: {
        id: number;
        project_id: number;
        title: string;
        summary: string;
        description: string;
        description_html: string;
        author_id: number;
        comments_count: number;
    };
    comments: CommentRow[];
    watcherIds: number[];
    watching: boolean;
    canComment: boolean;
    canManage: boolean;
};

export default function NewsShow({
    news,
    comments,
    watcherIds,
    watching,
    canComment,
    canManage,
}: NewsShowProps) {
    const form = useForm({ content: '' });

    function submitComment(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.post(`/news/${news.id}/comments`);
    }

    return (
        <>
            <Head title={news.title} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{news.title}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal news page. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        <p>{news.summary}</p>
                        {news.description_html === '' ? null : (
                            <div className="wiki text-sm" dangerouslySetInnerHTML={{ __html: news.description_html }} />
                        )}
                        <p className="text-sm text-muted-foreground">
                            Author {news.author_id} · {news.comments_count} comments
                        </p>
                        <p className="text-sm">Watchers: {watcherIds.length === 0 ? 'none' : watcherIds.join(', ')}</p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    watching
                                        ? router.delete(`/news/${news.id}/watch`)
                                        : router.post(`/news/${news.id}/watch`)
                                }
                            >
                                {watching ? 'Unwatch' : 'Watch'}
                            </Button>
                            {canManage ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => router.delete(`/news/${news.id}`)}
                                >
                                    Delete
                                </Button>
                            ) : null}
                            <Link
                                href={`/projects/${news.project_id}/news`}
                                className="text-sm underline-offset-4 hover:underline"
                            >
                                Project news
                            </Link>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Comments</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {comments.length === 0 ? <p className="text-sm">No comments.</p> : null}
                        <ul className="flex flex-col gap-2">
                            {comments.map((comment) => (
                                <li key={comment.id} className="text-sm">
                                    #{comment.id} by {comment.author_id}:{' '}
                                    <span dangerouslySetInnerHTML={{ __html: comment.content_html }} />
                                    {canManage ? (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="ml-2"
                                            onClick={() => router.delete(`/news/${news.id}/comments/${comment.id}`)}
                                        >
                                            Delete
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        {canComment ? (
                            <form className="flex flex-col gap-2" onSubmit={submitComment}>
                                <Label htmlFor="content">Comment</Label>
                                <Input
                                    id="content"
                                    value={form.data.content}
                                    onChange={(event) => form.setData('content', event.target.value)}
                                />
                                <Button type="submit" disabled={form.processing}>
                                    Add comment
                                </Button>
                            </form>
                        ) : null}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

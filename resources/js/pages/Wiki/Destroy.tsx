import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

type WikiDestroyProps = {
    project: string;
    title: string;
    slug: string;
    descendants: number;
    pages: { id: number; title: string }[];
};

export default function WikiDestroy({ project, title, slug, descendants, pages }: WikiDestroyProps) {
    const [todo, setTodo] = useState('nullify');
    const [reassignTo, setReassignTo] = useState('');

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        router.delete(`/projects/${project}/wiki/${slug}`, {
            data: {
                todo,
                reassign_to_id: reassignTo === '' ? undefined : Number(reassignTo),
            },
        });
    }

    return (
        <>
            <Head title={`Delete ${title}`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Delete {title}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-3" onSubmit={submit}>
                            <p className="text-sm">{descendants} child pages need a choice.</p>
                            <Label htmlFor="todo">Children</Label>
                            <select
                                id="todo"
                                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                value={todo}
                                onChange={(event) => setTodo(event.target.value)}
                            >
                                <option value="nullify">Clear parent</option>
                                <option value="reassign">Reassign</option>
                                <option value="destroy">Delete children</option>
                            </select>
                            {todo === 'reassign' ? (
                                <select
                                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                    value={reassignTo}
                                    onChange={(event) => setReassignTo(event.target.value)}
                                >
                                    <option value="">Choose a page</option>
                                    {pages
                                        .filter((page) => page.title !== title)
                                        .map((page) => (
                                            <option key={page.id} value={page.id}>
                                                {page.title}
                                            </option>
                                        ))}
                                </select>
                            ) : null}
                            <Button type="submit">Delete</Button>
                        </form>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
